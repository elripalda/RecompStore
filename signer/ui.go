package main

import (
	"crypto/sha256"
	"embed"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"html/template"
	"io"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

//go:embed page.html
var pageFS embed.FS

var page = template.Must(template.New("page.html").Funcs(template.FuncMap{
	"short": func(s string) string {
		if len(s) > 16 {
			return s[:16] + "…"
		}
		return s
	},
	"size": humanSize,
	"ago": func(t time.Time) string {
		if t.IsZero() {
			return "not yet"
		}
		d := time.Since(t).Round(time.Second)
		if d < time.Minute {
			return "just now"
		}
		return d.Round(time.Minute).String() + " ago"
	},
	"isdl": isDownloadPath,
	"join": strings.Join,
	"empty": func(s string) string {
		if s == "" {
			return "(nothing)"
		}
		return s
	},
}).ParseFS(pageFS, "page.html"))

func humanSize(n int64) string {
	switch {
	case n >= 1<<30:
		return fmt.Sprintf("%.1f GB", float64(n)/(1<<30))
	case n >= 1<<20:
		return fmt.Sprintf("%.0f MB", float64(n)/(1<<20))
	case n >= 1<<10:
		return fmt.Sprintf("%.0f KB", float64(n)/(1<<10))
	}
	return fmt.Sprintf("%d bytes", n)
}

type PendingView struct {
	P      Pending
	Key    string
	Title  string
	Review *Review
}

type pageData struct {
	Version, Base, State string
	KeysErr              string
	Flash                string
	FlashBad             bool
	BackupReady          bool
	Site                 string
	Pending              []PendingView
	PendingErr           string
	Checked              time.Time
	UsingBackup          bool
	PubText, ExportDir   string
	MainID, BackupID     string
	Startup              bool
	Rev                  int
}

func (a *App) data() pageData {
	d := pageData{Version: version, Base: "/" + a.secret + "/", Flash: a.flash, FlashBad: a.flashBad, Site: a.cfg.Site,
		PendingErr: a.pendingErr, Checked: a.checked, ExportDir: a.cfg.ExportDir, Startup: startsWithWindows(), Rev: a.rev,
		KeysErr: a.keysErr}
	switch {
	case a.keysErr != "":
		d.State = "error"
	case a.keys == nil || !a.keys.Ready():
		d.State = "makekeys"
	case !a.cfg.BackupSaved:
		d.State = "backup"
		d.BackupReady = a.backupText != ""
	case !a.connected():
		d.State = "connect"
	default:
		d.State = "main"
	}
	if a.keys != nil && a.keys.Ready() {
		d.UsingBackup = a.keys.UsingBackup
		d.PubText = a.keys.PublicKeysText()
		d.MainID, d.BackupID = KeyID(a.keys.MainPub), KeyID(a.keys.BackupPub)
	}
	for _, p := range a.pending {
		v := PendingView{P: p, Key: pendingKey(p), Review: a.reviews[pendingKey(p)]}
		switch p.Kind {
		case KindCatalog:
			v.Title = "Store catalog"
		case KindCatalogTest:
			v.Title = "Testers' catalog"
		case KindBeta:
			v.Title = "Beta zip: " + p.Name
		default:
			v.Title = p.Kind
		}
		d.Pending = append(d.Pending, v)
	}
	return d
}

var hostRe = regexp.MustCompile(`^127\.0\.0\.1:\d+$`)

func (a *App) routes() http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// Only this PC, only through the page's own address (no DNS rebinding), only with the
		// secret in the path, and POSTs only from the page itself.
		if !hostRe.MatchString(r.Host) || r.Host != fmt.Sprintf("127.0.0.1:%d", a.port) {
			http.Error(w, "no", http.StatusForbidden)
			return
		}
		prefix := "/" + a.secret + "/"
		if !strings.HasPrefix(r.URL.Path, prefix) {
			http.Error(w, "Open the signer from its icon.", http.StatusNotFound)
			return
		}
		if r.Method == http.MethodPost {
			if o := r.Header.Get("Origin"); o != "" && o != fmt.Sprintf("http://127.0.0.1:%d", a.port) {
				http.Error(w, "no", http.StatusForbidden)
				return
			}
			if sf := r.Header.Get("Sec-Fetch-Site"); sf != "" && sf != "same-origin" && sf != "none" {
				http.Error(w, "no", http.StatusForbidden)
				return
			}
		}
		w.Header().Set("Cache-Control", "no-store")
		w.Header().Set("X-Frame-Options", "DENY")
		w.Header().Set("Content-Security-Policy", "default-src 'self'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'")
		route := strings.TrimPrefix(r.URL.Path, prefix)
		a.handle(route, w, r)
	})
}

func (a *App) back(w http.ResponseWriter, r *http.Request) {
	http.Redirect(w, r, "/"+a.secret+"/", http.StatusSeeOther)
}

func (a *App) handle(route string, w http.ResponseWriter, r *http.Request) {
	switch {
	case route == "ping":
		fmt.Fprint(w, "ok")
	case route == "" && r.Method == http.MethodGet:
		a.mu.Lock()
		d := a.data()
		a.mu.Unlock()
		w.Header().Set("Content-Type", "text/html; charset=utf-8")
		if err := page.Execute(w, d); err != nil {
			log.Printf("page: %v", err)
		}
	case route == "rev":
		a.mu.Lock()
		fmt.Fprint(w, a.rev)
		a.mu.Unlock()
	case route == "make-keys" && r.Method == http.MethodPost:
		k, backup, err := MakeKeys()
		a.mu.Lock()
		if err != nil {
			a.setFlash("The keys weren't made: "+err.Error(), true)
		} else {
			a.keys, a.backupText, a.keysErr = k, backup, ""
			a.cfg.BackupSaved = false
			_ = a.cfg.Save()
			a.setFlash("Your keys are made. Now save the backup key.", false)
		}
		a.mu.Unlock()
		a.back(w, r)
	case route == "backup-key" && r.Method == http.MethodGet:
		a.mu.Lock()
		t := a.backupText
		a.mu.Unlock()
		if t == "" {
			http.Error(w, "The backup key was already handed over.", http.StatusGone)
			return
		}
		w.Header().Set("Content-Type", "text/plain; charset=utf-8")
		w.Header().Set("Content-Disposition", `attachment; filename="RecompStore-backup-key.txt"`)
		fmt.Fprint(w, t)
	case route == "backup-saved" && r.Method == http.MethodPost:
		a.mu.Lock()
		if r.FormValue("sure") != "1" {
			a.setFlash("Tick the box once the backup key is on a USB drive.", true)
		} else {
			a.backupText = ""
			a.cfg.BackupSaved = true
			_ = a.cfg.Save()
			if err := a.exportPublic(); err != nil {
				a.setFlash("Saved. The public keys file couldn't be written: "+err.Error(), true)
			} else {
				a.setFlash("Done. Your public keys are in "+filepath.Join(a.cfg.ExportDir, "public-keys.txt")+".", false)
			}
		}
		a.mu.Unlock()
		a.back(w, r)
	case route == "restore" && r.Method == http.MethodPost:
		k, err := RestoreBackup(r.FormValue("backup"))
		a.mu.Lock()
		if err != nil {
			a.setFlash(err.Error(), true)
		} else {
			a.keys, a.keysErr = k, ""
			a.cfg.BackupSaved = true
			_ = a.cfg.Save()
			_ = a.exportPublic()
			a.setFlash("The backup key now signs on this PC.", false)
		}
		a.mu.Unlock()
		a.back(w, r)
	case route == "connect" && r.Method == http.MethodPost:
		site := strings.TrimRight(strings.TrimSpace(r.FormValue("site")), "/")
		token := strings.TrimSpace(r.FormValue("token"))
		if site == "" {
			site = defaultSite
		}
		if !strings.HasPrefix(site, "https://") && !strings.HasPrefix(site, "http://127.0.0.1") && !strings.HasPrefix(site, "http://localhost") {
			a.mu.Lock()
			a.setFlash("The site address has to start with https://", true)
			a.mu.Unlock()
			a.back(w, r)
			return
		}
		a.mu.Lock()
		pub := a.keys.PublicKeysText()
		a.mu.Unlock()
		ans, err := NewSite(site, token).Hello(pub)
		a.mu.Lock()
		if err != nil {
			a.setFlash("That didn't connect: "+err.Error(), true)
		} else {
			a.cfg.Site = site
			_ = a.cfg.SetToken(token)
			_ = a.cfg.Save()
			msg := "Connected to " + site + "."
			if ans.Message != "" {
				msg += " " + ans.Message
			}
			a.setFlash(msg, false)
		}
		a.mu.Unlock()
		go a.poll()
		time.Sleep(700 * time.Millisecond)
		a.back(w, r)
	case route == "disconnect" && r.Method == http.MethodPost:
		a.mu.Lock()
		a.cfg.TokenSealed = ""
		_ = a.cfg.Save()
		a.pending, a.reviews = nil, map[string]*Review{}
		a.setFlash("Disconnected.", false)
		a.mu.Unlock()
		a.back(w, r)
	case route == "refresh" && r.Method == http.MethodPost:
		a.poll()
		a.back(w, r)
	case route == "review" && r.Method == http.MethodPost:
		a.review(r.FormValue("key"))
		a.back(w, r)
	case route == "sign" && r.Method == http.MethodPost:
		a.sign(r.FormValue("key"))
		a.back(w, r)
	case route == "signfile" && r.Method == http.MethodPost:
		a.signFile(w, r)
	case route == "startup" && r.Method == http.MethodPost:
		on := r.FormValue("on") == "1"
		err := setStartsWithWindows(on)
		a.mu.Lock()
		if err != nil {
			a.setFlash("Windows didn't take that: "+err.Error(), true)
		} else if on {
			a.setFlash("The signer starts with Windows now and tells you when something needs signing.", false)
		} else {
			a.setFlash("The signer won't start with Windows.", false)
		}
		a.mu.Unlock()
		a.back(w, r)
	case route == "public-keys.txt":
		a.mu.Lock()
		t := ""
		if a.keys != nil && a.keys.Ready() {
			t = a.keys.PublicKeysText()
		}
		a.mu.Unlock()
		w.Header().Set("Content-Type", "text/plain; charset=utf-8")
		fmt.Fprint(w, t)
	default:
		http.NotFound(w, r)
	}
}

func (a *App) exportPublic() error {
	if a.keys == nil || !a.keys.Ready() {
		return nil
	}
	if err := os.MkdirAll(a.cfg.ExportDir, 0o755); err != nil {
		return err
	}
	return os.WriteFile(filepath.Join(a.cfg.ExportDir, "public-keys.txt"), []byte(a.keys.PublicKeysText()), 0o644)
}

func (a *App) find(key string) (Pending, bool) {
	for _, p := range a.pending {
		if pendingKey(p) == key {
			return p, true
		}
	}
	return Pending{}, false
}

// review fetches a catalog's exact bytes and works out what changed since the last signed one.
func (a *App) review(key string) {
	a.mu.Lock()
	p, ok := a.find(key)
	s := a.site()
	a.mu.Unlock()
	if !ok || (p.Kind != KindCatalog && p.Kind != KindCatalogTest) {
		return
	}
	rv := &Review{P: p}
	data, err := s.Get(p)
	if err != nil {
		rv.Error = err.Error()
	} else {
		prev, _ := os.ReadFile(lastCopyFile(p.Kind))
		rv.Data, rv.Diff = data, CatalogDiff(prev, data)
	}
	a.mu.Lock()
	a.reviews[key] = rv
	a.rev++
	a.mu.Unlock()
}

func (a *App) sign(key string) {
	a.mu.Lock()
	p, ok := a.find(key)
	rv := a.reviews[key]
	s := a.site()
	k := a.keys
	a.mu.Unlock()
	fail := func(msg string) {
		a.mu.Lock()
		a.setFlash(msg, true)
		a.mu.Unlock()
	}
	if !ok {
		fail("That isn't waiting anymore. The list is refreshed.")
		a.poll()
		return
	}
	var sig Signature
	now := time.Now()
	switch p.Kind {
	case KindCatalog, KindCatalogTest:
		if rv == nil || rv.Data == nil || rv.Diff.Bad != "" {
			fail("Look over the changes first.")
			return
		}
		sig = Sign(k.Main, p.Kind, p.File, rv.Data, now)
		if sig.SHA256 != strings.ToLower(p.SHA256) {
			fail("The catalog changed while you were looking. Look again.")
			return
		}
		if last, ok := a.cfg.Last[p.Kind]; ok && !now.After(last.Signed) {
			fail("This PC's clock is behind the last signature. Fix the clock, then sign.")
			return
		}
	case KindBeta:
		sig = SignHash(k.Main, KindBeta, p.File, p.Size, p.SHA256, now)
	default:
		fail("The signer doesn't know how to sign that.")
		return
	}
	msg, err := s.Put(sig, p.ID)
	if err != nil {
		fail("The signature didn't go up: " + err.Error())
		return
	}
	a.mu.Lock()
	if p.Kind == KindCatalog || p.Kind == KindCatalogTest {
		_ = writeFileAtomic(lastCopyFile(p.Kind), rv.Data, 0o600)
	}
	a.cfg.Last[p.Kind] = Last{SHA256: sig.SHA256, Signed: sig.Signed}
	_ = a.cfg.Save()
	if msg == "" {
		msg = "Signed."
	}
	a.setFlash(msg, false)
	a.mu.Unlock()
	a.poll()
}

// signFile signs a file dropped on the page: a store update zip, or a beta zip from this PC.
func (a *App) signFile(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	reply := func(code int, v map[string]any) {
		w.WriteHeader(code)
		_ = json.NewEncoder(w).Encode(v)
	}
	if r.Header.Get("X-Signer-UI") != "1" {
		reply(403, map[string]any{"ok": false, "error": "no"})
		return
	}
	kind := r.URL.Query().Get("kind")
	name := filepath.Base(r.URL.Query().Get("name"))
	if kind != KindStore && kind != KindBeta {
		reply(400, map[string]any{"ok": false, "error": "Pick store update or beta zip."})
		return
	}
	if name == "" || name == "." || strings.ContainsAny(name, `/\:*?"<>|`) {
		reply(400, map[string]any{"ok": false, "error": "That file name won't do."})
		return
	}
	h := sha256.New()
	n, err := io.Copy(h, r.Body)
	if err != nil || n == 0 {
		reply(400, map[string]any{"ok": false, "error": "The file didn't come through."})
		return
	}
	sha := hex.EncodeToString(h.Sum(nil))
	a.mu.Lock()
	k := a.keys
	dir := a.cfg.ExportDir
	connected := a.connected()
	s := a.site()
	a.mu.Unlock()
	if k == nil || !k.Ready() {
		reply(400, map[string]any{"ok": false, "error": "Make your keys first."})
		return
	}
	sig := SignHash(k.Main, kind, name, n, sha, time.Now())
	_ = os.MkdirAll(dir, 0o755)
	path := filepath.Join(dir, name+".sig")
	if err := os.WriteFile(path, []byte(sig.Text()), 0o644); err != nil {
		reply(500, map[string]any{"ok": false, "error": "The signature couldn't be saved: " + err.Error()})
		return
	}
	msg := "Signed. Saved to " + path + "."
	if kind == KindBeta && connected {
		if m, err := s.Put(sig, ""); err != nil {
			msg += " The site didn't take it: " + err.Error()
		} else if m != "" {
			msg += " " + m
		}
	}
	a.mu.Lock()
	a.setFlash(msg, false)
	a.mu.Unlock()
	reply(200, map[string]any{"ok": true, "sha256": sha, "size": n, "path": path, "sig": sig.Text(), "message": msg})
}
