package main

// The site's side: /api/sign.php, answered only with the signer token (made in the admin's
// Settings). The token only lets the signer read what's waiting and hand signatures over;
// a signature still has to check out against the keys built into the store.

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

type Pending struct {
	Kind   string `json:"kind"`
	ID     string `json:"id,omitempty"`   // a beta's game ID
	Name   string `json:"name,omitempty"` // shown: "GTA IV BETA"
	File   string `json:"file"`
	Size   int64  `json:"size"`
	SHA256 string `json:"sha256"`
	When   string `json:"when,omitempty"`
	Note   string `json:"note,omitempty"`
}

type Site struct {
	Base  string
	Token string
	c     *http.Client
}

func NewSite(base, token string) *Site {
	return &Site{Base: strings.TrimRight(base, "/"), Token: token, c: &http.Client{Timeout: 60 * time.Second}}
}

func (s *Site) req(method, a string, form url.Values) (*http.Response, error) {
	u := s.Base + "/api/sign.php?a=" + url.QueryEscape(a)
	var body io.Reader
	if form != nil && method == http.MethodGet {
		u += "&" + form.Encode()
	} else if form != nil {
		body = strings.NewReader(form.Encode())
	}
	r, err := http.NewRequest(method, u, body)
	if err != nil {
		return nil, err
	}
	r.Header.Set("X-Signer-Token", s.Token)
	r.Header.Set("User-Agent", "RecompStoreSigner/"+version)
	if body != nil {
		r.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	}
	return s.c.Do(r)
}

type apiAnswer struct {
	OK      bool      `json:"ok"`
	Error   string    `json:"error"`
	Items   []Pending `json:"items"`
	Keys    []string  `json:"keys"`
	Message string    `json:"message"`
}

func (s *Site) call(method, a string, form url.Values) (*apiAnswer, error) {
	resp, err := s.req(method, a, form)
	if err != nil {
		return nil, fmt.Errorf("couldn't reach %s (%v)", s.Base, err)
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(io.LimitReader(resp.Body, 4<<20))
	var ans apiAnswer
	if json.Unmarshal(b, &ans) != nil {
		return nil, fmt.Errorf("%s answered HTTP %d, not the signer's answer", s.Base, resp.StatusCode)
	}
	if !ans.OK {
		if ans.Error == "" {
			ans.Error = fmt.Sprintf("HTTP %d", resp.StatusCode)
		}
		return &ans, errors.New(ans.Error)
	}
	return &ans, nil
}

// Hello checks the token and tells the site the public keys (it keeps the first ones it's told).
func (s *Site) Hello(pubText string) (*apiAnswer, error) {
	return s.call(http.MethodPost, "hello", url.Values{"keys": {pubText}})
}

func (s *Site) Pending() ([]Pending, error) {
	ans, err := s.call(http.MethodGet, "pending", nil)
	if err != nil {
		return nil, err
	}
	return ans.Items, nil
}

// Get fetches the exact bytes waiting to be signed and checks they're the ones listed.
func (s *Site) Get(p Pending) ([]byte, error) {
	resp, err := s.req(http.MethodGet, "get", url.Values{"kind": {p.Kind}, "id": {p.ID}})
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return nil, fmt.Errorf("the site answered HTTP %d", resp.StatusCode)
	}
	b, err := io.ReadAll(io.LimitReader(resp.Body, 64<<20))
	if err != nil {
		return nil, err
	}
	h := sha256.Sum256(b)
	if hex.EncodeToString(h[:]) != strings.ToLower(p.SHA256) {
		return nil, errors.New("what the site sent doesn't match what it listed; try again")
	}
	return b, nil
}

func (s *Site) Put(sig Signature, id string) (string, error) {
	ans, err := s.call(http.MethodPost, "put", url.Values{"sig": {sig.Text()}, "id": {id}})
	if err != nil {
		return "", err
	}
	return ans.Message, nil
}
