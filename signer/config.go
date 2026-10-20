package main

import (
	"encoding/base64"
	"encoding/json"
	"os"
	"path/filepath"
	"time"
)

type Last struct {
	SHA256 string    `json:"sha256"`
	Signed time.Time `json:"signed"`
}

type Config struct {
	Site        string          `json:"site"`
	TokenSealed string          `json:"token_sealed,omitempty"`
	ExportDir   string          `json:"export_dir,omitempty"`
	BackupSaved bool            `json:"backup_saved"`
	Last        map[string]Last `json:"last,omitempty"`
}

const defaultSite = "https://recompstore.com"

func configFile() string { return filepath.Join(dataDir(), "config.json") }

func LoadConfig() *Config {
	c := &Config{Site: defaultSite, Last: map[string]Last{}}
	if b, err := os.ReadFile(configFile()); err == nil {
		_ = json.Unmarshal(b, c)
	}
	if c.Site == "" {
		c.Site = defaultSite
	}
	if c.Last == nil {
		c.Last = map[string]Last{}
	}
	if c.ExportDir == "" {
		c.ExportDir = defaultExportDir()
	}
	return c
}

func (c *Config) Save() error {
	b, _ := json.MarshalIndent(c, "", "  ")
	return writeFileAtomic(configFile(), b, 0o600)
}

func (c *Config) Token() string {
	if c.TokenSealed == "" {
		return ""
	}
	raw, err := base64.StdEncoding.DecodeString(c.TokenSealed)
	if err != nil {
		return ""
	}
	t, err := unprotect(raw)
	if err != nil {
		return ""
	}
	return string(t)
}

func (c *Config) SetToken(t string) error {
	sealed, err := protect([]byte(t))
	if err != nil {
		return err
	}
	c.TokenSealed = base64.StdEncoding.EncodeToString(sealed)
	return nil
}

// Where signatures and the public keys file go: Documents\Source Code\recompstore\signing
// when that folder exists (the person keeps the store's source there), else the signer's own.
func defaultExportDir() string {
	home, err := os.UserHomeDir()
	if err == nil {
		src := filepath.Join(home, "Documents", "Source Code", "recompstore")
		if st, err := os.Stat(src); err == nil && st.IsDir() {
			return filepath.Join(src, "signing")
		}
	}
	return filepath.Join(dataDir(), "signatures")
}

// The last signed copy of a catalog, to show what changed since.
func lastCopyFile(kind string) string { return filepath.Join(dataDir(), "last-"+kind+".json") }
