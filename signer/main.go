package main

// Recomp Store Signer: signs the store's catalog, store updates and beta zips on this PC, so a
// break-in at the website or GitHub can't push anything to PS5s. Its window is a page on
// 127.0.0.1 that only this PC can open.

import (
	"crypto/rand"
	"encoding/hex"
	"flag"
	"fmt"
	"log"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"time"
)

const version = "1.0.0"
const firstPort = 47321

type Review struct {
	P     Pending
	Data  []byte
	Diff  Diff
	Error string
}

type App struct {
	mu         sync.Mutex
	cfg        *Config
	keys       *Keys
	keysErr    string
	pending    []Pending
	pendingErr string
	checked    time.Time
	reviews    map[string]*Review
	backupText string
	notified   map[string]bool
	flash      string
	flashBad   bool
	port       int
	secret     string
	rev        int
}

func pendingKey(p Pending) string { return p.Kind + "|" + p.ID + "|" + p.SHA256 }

func (a *App) site() *Site { return NewSite(a.cfg.Site, a.cfg.Token()) }

func (a *App) connected() bool { return a.cfg.Token() != "" }

func (a *App) setFlash(msg string, bad bool) {
	a.flash, a.flashBad = msg, bad
	a.rev++
}

// poll asks the site what's waiting, and notifies about anything new.
func (a *App) poll() {
	a.mu.Lock()
	if a.keys == nil || !a.keys.Ready() || !a.connected() {
		a.mu.Unlock()
		return
	}
	s := a.site()
	a.mu.Unlock()
	items, err := s.Pending()
	a.mu.Lock()
	defer a.mu.Unlock()
	a.checked = time.Now()
	if err != nil {
		if a.pendingErr != err.Error() {
			a.rev++
		}
		a.pendingErr = err.Error()
		return
	}
	a.pendingErr = ""
	var fresh []string
	keep := map[string]*Review{}
	changed := len(items) != len(a.pending)
	for i, p := range items {
		k := pendingKey(p)
		if r, ok := a.reviews[k]; ok {
			keep[k] = r
		}
		if !a.notified[k] {
			a.notified[k] = true
			fresh = append(fresh, describe(p))
		}
		if !changed && pendingKey(a.pending[i]) != k {
			changed = true
		}
	}
	a.reviews = keep
	a.pending = items
	if changed {
		a.rev++
	}
	if len(fresh) > 0 {
		msg := fresh[0]
		if len(fresh) > 1 {
			msg = fmt.Sprintf("%d things are waiting for your signature.", len(fresh))
		}
		go notify("Recomp Store Signer", msg, a.url())
	}
}

func describe(p Pending) string {
	switch p.Kind {
	case KindCatalog:
		return "The store catalog changed and is waiting for your signature."
	case KindCatalogTest:
		return "The testers' catalog changed and is waiting for your signature."
	case KindBeta:
		n := p.Name
		if n == "" {
			n = p.ID
		}
		return "A new beta zip for " + n + " is waiting for your signature."
	}
	return "Something is waiting for your signature."
}

func (a *App) url() string { return fmt.Sprintf("http://127.0.0.1:%d/%s/", a.port, a.secret) }

func sessionFile() string { return filepath.Join(dataDir(), "session.txt") }

// An already running signer: open its page instead of starting another.
func openRunning() bool {
	b, err := os.ReadFile(sessionFile())
	if err != nil {
		return false
	}
	parts := strings.Fields(string(b))
	if len(parts) != 2 {
		return false
	}
	port, _ := strconv.Atoi(parts[0])
	c := http.Client{Timeout: 2 * time.Second}
	resp, err := c.Get(fmt.Sprintf("http://127.0.0.1:%d/%s/ping", port, parts[1]))
	if err != nil {
		return false
	}
	resp.Body.Close()
	if resp.StatusCode != 200 {
		return false
	}
	openBrowser(fmt.Sprintf("http://127.0.0.1:%d/%s/", port, parts[1]))
	return true
}

func main() {
	background := flag.Bool("background", false, "start without opening the page (with Windows)")
	flag.Parse()
	_ = os.MkdirAll(dataDir(), 0o700)
	if lf, err := os.OpenFile(filepath.Join(dataDir(), "signer.log"), os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o600); err == nil {
		log.SetOutput(lf)
	}
	if openRunning() {
		return
	}
	var ln net.Listener
	var err error
	port := firstPort
	for ; port < firstPort+20; port++ {
		ln, err = net.Listen("tcp", fmt.Sprintf("127.0.0.1:%d", port))
		if err == nil {
			break
		}
	}
	if ln == nil {
		log.Fatalf("no free port: %v", err)
	}
	sec := make([]byte, 16)
	_, _ = rand.Read(sec)
	a := &App{cfg: LoadConfig(), reviews: map[string]*Review{}, notified: map[string]bool{}, port: port, secret: hex.EncodeToString(sec)}
	k, kerr := LoadKeys()
	a.keys = k
	if kerr != nil {
		a.keysErr = kerr.Error()
	}
	_ = writeFileAtomic(sessionFile(), []byte(fmt.Sprintf("%d %s\n", port, a.secret)), 0o600)
	log.Printf("signer %s on %s", version, a.url())
	go func() {
		for {
			a.poll()
			time.Sleep(60 * time.Second)
		}
	}()
	if !*background {
		go func() {
			time.Sleep(300 * time.Millisecond)
			openBrowser(a.url())
		}()
	}
	srv := &http.Server{Handler: a.routes(), ReadHeaderTimeout: 10 * time.Second}
	log.Fatal(srv.Serve(ln))
}
