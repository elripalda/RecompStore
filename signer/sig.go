package main

// Recomp Store signatures: a small text file next to what it signs.
//
//	RecompStore signature v1
//	kind: catalog
//	file: catalog.json
//	size: 48213
//	sha256: <64 hex>
//	signed: 2026-10-20T04:30:00Z
//	key: <16 hex, the first 8 bytes of SHA-256 of the public key>
//	sig: <128 hex, Ed25519>
//
// The signature covers the message from SignedMessage: the kind, the SHA-256 of the bytes
// and the time, one per line. The store (C, Monocypher) and the site (PHP, sodium) build the
// same message the same way.

import (
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"strconv"
	"strings"
	"time"
)

const sigHeader = "RecompStore signature v1"

// Kinds of things that get signed.
const (
	KindCatalog     = "catalog"      // catalog.json, what every store reads
	KindCatalogTest = "catalog-test" // the catalog testers' stores read
	KindStore       = "store"        // PPSA92000.zip, the store's own update
	KindBeta        = "beta"         // a beta game's zip
)

func validKind(k string) bool {
	switch k {
	case KindCatalog, KindCatalogTest, KindStore, KindBeta:
		return true
	}
	return false
}

type Signature struct {
	Kind   string
	File   string
	Size   int64
	SHA256 string // lower-case hex
	Signed time.Time
	KeyID  string
	Sig    []byte
}

func KeyID(pub ed25519.PublicKey) string {
	h := sha256.Sum256(pub)
	return hex.EncodeToString(h[:8])
}

func SignedMessage(kind, sha string, signed time.Time) []byte {
	return []byte(sigHeader + "\nkind: " + kind + "\nsha256: " + sha + "\nsigned: " + signed.UTC().Format(time.RFC3339) + "\n")
}

func Sign(priv ed25519.PrivateKey, kind, file string, data []byte, now time.Time) Signature {
	h := sha256.Sum256(data)
	return SignHash(priv, kind, file, int64(len(data)), hex.EncodeToString(h[:]), now)
}

func SignHash(priv ed25519.PrivateKey, kind, file string, size int64, sha string, now time.Time) Signature {
	now = now.UTC().Truncate(time.Second)
	pub := priv.Public().(ed25519.PublicKey)
	return Signature{
		Kind:   kind,
		File:   file,
		Size:   size,
		SHA256: strings.ToLower(sha),
		Signed: now,
		KeyID:  KeyID(pub),
		Sig:    ed25519.Sign(priv, SignedMessage(kind, strings.ToLower(sha), now)),
	}
}

func (s Signature) Text() string {
	var b strings.Builder
	b.WriteString(sigHeader + "\n")
	fmt.Fprintf(&b, "kind: %s\n", s.Kind)
	fmt.Fprintf(&b, "file: %s\n", s.File)
	fmt.Fprintf(&b, "size: %d\n", s.Size)
	fmt.Fprintf(&b, "sha256: %s\n", s.SHA256)
	fmt.Fprintf(&b, "signed: %s\n", s.Signed.UTC().Format(time.RFC3339))
	fmt.Fprintf(&b, "key: %s\n", s.KeyID)
	fmt.Fprintf(&b, "sig: %s\n", hex.EncodeToString(s.Sig))
	return b.String()
}

func ParseSignature(text string) (Signature, error) {
	var s Signature
	lines := strings.Split(strings.ReplaceAll(text, "\r\n", "\n"), "\n")
	if len(lines) == 0 || strings.TrimSpace(lines[0]) != sigHeader {
		return s, errors.New("not a Recomp Store signature")
	}
	for _, l := range lines[1:] {
		k, v, ok := strings.Cut(l, ": ")
		if !ok {
			continue
		}
		v = strings.TrimSpace(v)
		switch k {
		case "kind":
			s.Kind = v
		case "file":
			s.File = v
		case "size":
			s.Size, _ = strconv.ParseInt(v, 10, 64)
		case "sha256":
			s.SHA256 = strings.ToLower(v)
		case "signed":
			t, err := time.Parse(time.RFC3339, v)
			if err != nil {
				return s, errors.New("bad time")
			}
			s.Signed = t
		case "key":
			s.KeyID = v
		case "sig":
			b, err := hex.DecodeString(v)
			if err != nil || len(b) != ed25519.SignatureSize {
				return s, errors.New("bad signature")
			}
			s.Sig = b
		}
	}
	if !validKind(s.Kind) || len(s.SHA256) != 64 || s.Signed.IsZero() || len(s.Sig) == 0 {
		return s, errors.New("incomplete signature")
	}
	return s, nil
}

func (s Signature) Verify(keys []ed25519.PublicKey) bool {
	for _, k := range keys {
		if KeyID(k) == s.KeyID && ed25519.Verify(k, SignedMessage(s.Kind, s.SHA256, s.Signed), s.Sig) {
			return true
		}
	}
	return false
}
