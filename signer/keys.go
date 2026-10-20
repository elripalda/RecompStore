package main

// The signing keys. The main key's private half lives only on this PC, locked to the Windows
// account with DPAPI (protect/unprotect in dpapi_*.go). The backup key is made at the same time;
// its private half is handed to the person once, to keep offline (a USB drive), and only its
// public half stays here. The store trusts both public keys, so the backup can sign if this PC
// is ever lost.

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"
)

type Keys struct {
	Main        ed25519.PrivateKey // nil until made or restored
	MainPub     ed25519.PublicKey
	BackupPub   ed25519.PublicKey
	UsingBackup bool // signing with the backup key (restored onto this PC)
}

func dataDir() string {
	base, err := os.UserConfigDir()
	if err != nil || base == "" {
		base = "."
	}
	return filepath.Join(base, "RecompStoreSigner")
}

func keyFile() string       { return filepath.Join(dataDir(), "main.key") }
func backupPubFile() string { return filepath.Join(dataDir(), "backup.pub") }
func mainPubFile() string   { return filepath.Join(dataDir(), "main.pub") }

func LoadKeys() (*Keys, error) {
	k := &Keys{}
	if b, err := os.ReadFile(mainPubFile()); err == nil {
		if p, err := hex.DecodeString(strings.TrimSpace(string(b))); err == nil && len(p) == ed25519.PublicKeySize {
			k.MainPub = p
		}
	}
	if b, err := os.ReadFile(backupPubFile()); err == nil {
		if p, err := hex.DecodeString(strings.TrimSpace(string(b))); err == nil && len(p) == ed25519.PublicKeySize {
			k.BackupPub = p
		}
	}
	sealed, err := os.ReadFile(keyFile())
	if errors.Is(err, os.ErrNotExist) {
		return k, nil
	}
	if err != nil {
		return k, err
	}
	seed, err := unprotect(sealed)
	if err != nil {
		return k, fmt.Errorf("the key on this PC couldn't be opened (%v)", err)
	}
	if len(seed) != ed25519.SeedSize {
		return k, errors.New("the key on this PC is damaged")
	}
	k.Main = ed25519.NewKeyFromSeed(seed)
	pub := k.Main.Public().(ed25519.PublicKey)
	switch {
	case k.MainPub != nil && string(pub) == string(k.MainPub):
	case k.BackupPub != nil && string(pub) == string(k.BackupPub):
		k.UsingBackup = true
	default:
		return k, errors.New("the key on this PC doesn't match its public key")
	}
	return k, nil
}

func (k *Keys) Ready() bool { return k.Main != nil && k.MainPub != nil && k.BackupPub != nil }

func (k *Keys) Trusted() []ed25519.PublicKey {
	var out []ed25519.PublicKey
	if k.MainPub != nil {
		out = append(out, k.MainPub)
	}
	if k.BackupPub != nil {
		out = append(out, k.BackupPub)
	}
	return out
}

// MakeKeys makes the main key (kept here) and the backup key, and returns the backup key's
// private half as the text of the file the person keeps.
func MakeKeys() (*Keys, string, error) {
	if _, err := os.Stat(keyFile()); err == nil {
		return nil, "", errors.New("this PC already has a signing key")
	}
	if err := os.MkdirAll(dataDir(), 0o700); err != nil {
		return nil, "", err
	}
	mainPub, mainPriv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		return nil, "", err
	}
	backupPub, backupPriv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		return nil, "", err
	}
	sealed, err := protect(mainPriv.Seed())
	if err != nil {
		return nil, "", fmt.Errorf("Windows couldn't lock the key (%v)", err)
	}
	if err := writeFileAtomic(keyFile(), sealed, 0o600); err != nil {
		return nil, "", err
	}
	if err := writeFileAtomic(mainPubFile(), []byte(hex.EncodeToString(mainPub)+"\n"), 0o644); err != nil {
		return nil, "", err
	}
	if err := writeFileAtomic(backupPubFile(), []byte(hex.EncodeToString(backupPub)+"\n"), 0o644); err != nil {
		return nil, "", err
	}
	k := &Keys{Main: mainPriv, MainPub: mainPub, BackupPub: backupPub}
	return k, BackupKeyText(backupPriv, mainPub), nil
}

func BackupKeyText(priv ed25519.PrivateKey, mainPub ed25519.PublicKey) string {
	pub := priv.Public().(ed25519.PublicKey)
	return "Recomp Store backup signing key\n" +
		"Keep this file offline (a USB drive in a drawer), never online and never in a chat.\n" +
		"It can sign catalogs and store updates if your PC's key is ever lost.\n" +
		"Made: " + time.Now().Format("2006-01-02") + "\n\n" +
		"backup-public: " + hex.EncodeToString(pub) + "\n" +
		"main-public: " + hex.EncodeToString(mainPub) + "\n" +
		"backup-private: " + hex.EncodeToString(priv.Seed()) + "\n"
}

// RestoreBackup puts the backup key on this PC (after the main key was lost), so it signs.
func RestoreBackup(text string) (*Keys, error) {
	var seedHex, mainHex string
	for _, l := range strings.Split(strings.ReplaceAll(text, "\r\n", "\n"), "\n") {
		if v, ok := strings.CutPrefix(l, "backup-private: "); ok {
			seedHex = strings.TrimSpace(v)
		}
		if v, ok := strings.CutPrefix(l, "main-public: "); ok {
			mainHex = strings.TrimSpace(v)
		}
	}
	seed, err := hex.DecodeString(seedHex)
	if err != nil || len(seed) != ed25519.SeedSize {
		return nil, errors.New("that isn't a Recomp Store backup key file")
	}
	mainPub, err := hex.DecodeString(mainHex)
	if err != nil || len(mainPub) != ed25519.PublicKeySize {
		return nil, errors.New("the backup key file is missing its main-public line")
	}
	if err := os.MkdirAll(dataDir(), 0o700); err != nil {
		return nil, err
	}
	priv := ed25519.NewKeyFromSeed(seed)
	sealed, err := protect(seed)
	if err != nil {
		return nil, err
	}
	if err := writeFileAtomic(keyFile(), sealed, 0o600); err != nil {
		return nil, err
	}
	pub := priv.Public().(ed25519.PublicKey)
	_ = writeFileAtomic(mainPubFile(), []byte(hex.EncodeToString(mainPub)+"\n"), 0o644)
	_ = writeFileAtomic(backupPubFile(), []byte(hex.EncodeToString(pub)+"\n"), 0o644)
	return &Keys{Main: priv, MainPub: mainPub, BackupPub: pub, UsingBackup: true}, nil
}

// PublicKeysText is what goes into the store's build and the site: public halves only.
func (k *Keys) PublicKeysText() string {
	return "Recomp Store public keys v1\n" +
		"main: " + hex.EncodeToString(k.MainPub) + " (" + KeyID(k.MainPub) + ")\n" +
		"backup: " + hex.EncodeToString(k.BackupPub) + " (" + KeyID(k.BackupPub) + ")\n"
}

func writeFileAtomic(path string, data []byte, mode os.FileMode) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return err
	}
	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, data, mode); err != nil {
		return err
	}
	return os.Rename(tmp, path)
}
