package main

// What changed in a catalog since the last one signed, worked out here from the exact bytes
// about to be signed (never from anything the site says about them).

import (
	"encoding/json"
	"fmt"
	"sort"
	"strings"
)

type FieldChange struct {
	Path   string
	Before string
	After  string
}

type AppChange struct {
	ID       string
	Name     string
	List     string // the catalog list it's in ("apps")
	Download []FieldChange
	Pictures []string
	Text     []string
}

type AppLine struct {
	ID, Name, List, From string
}

type Diff struct {
	First    bool
	Count    int
	Added    []AppLine
	Removed  []AppLine
	Changed  []AppChange
	Settings []FieldChange // top-level keys that aren't app lists
	Bad      string        // the new bytes aren't a catalog at all
}

func (d *Diff) DownloadChanges() int {
	n := len(d.Added)
	for _, c := range d.Changed {
		if len(c.Download) > 0 {
			n++
		}
	}
	for _, s := range d.Settings {
		if isDownloadPath(s.Path) {
			n++
		}
	}
	return n
}

func (d *Diff) Empty() bool {
	return !d.First && len(d.Added) == 0 && len(d.Removed) == 0 && len(d.Changed) == 0 && len(d.Settings) == 0
}

type entry struct {
	list string
	obj  map[string]any
}

// lists finds the catalog's lists of apps: top-level arrays of objects that have an "id".
func lists(root map[string]any) (map[string]entry, map[string]any) {
	apps := map[string]entry{}
	rest := map[string]any{}
	for k, v := range root {
		arr, ok := v.([]any)
		isList := ok && len(arr) > 0
		if isList {
			for _, it := range arr {
				o, ok := it.(map[string]any)
				if !ok || fmt.Sprint(o["id"]) == "" || o["id"] == nil {
					isList = false
					break
				}
			}
		}
		if !isList {
			rest[k] = v
			continue
		}
		for _, it := range arr {
			o := it.(map[string]any)
			apps[k+"\x00"+fmt.Sprint(o["id"])] = entry{list: k, obj: o}
		}
	}
	return apps, rest
}

func flatten(prefix string, v any, out map[string]string) {
	switch t := v.(type) {
	case map[string]any:
		for k, x := range t {
			p := k
			if prefix != "" {
				p = prefix + "." + k
			}
			flatten(p, x, out)
		}
	case []any:
		for i, x := range t {
			flatten(fmt.Sprintf("%s[%d]", prefix, i), x, out)
		}
	case nil:
		out[prefix] = ""
	default:
		b, _ := json.Marshal(t)
		s := string(b)
		if str, ok := t.(string); ok {
			s = str
		}
		out[prefix] = s
	}
}

func isPicturePath(p string) bool {
	l := strings.ToLower(p)
	for _, w := range []string{"icon", "logo", "hero", "banner", "art", "media", "screenshot", "picture", "image", "background"} {
		if strings.Contains(l, w) {
			return true
		}
	}
	return false
}

// Where an app's files come from: what a hijacker would change.
func isDownloadPath(p string) bool {
	if isPicturePath(p) {
		return false
	}
	l := strings.ToLower(p)
	for _, w := range []string{"source", "repo", "asset", "inside", "url", "sha256", "sha1", "slots", "update", "download", "payload", "store"} {
		if strings.Contains(l, w) {
			return true
		}
	}
	return false
}

func appName(o map[string]any) string {
	for _, k := range []string{"name", "title"} {
		if s, ok := o[k].(string); ok && s != "" {
			return s
		}
	}
	return fmt.Sprint(o["id"])
}

func appFrom(o map[string]any) string {
	src, _ := o["source"].(map[string]any)
	typ, _ := src["type"].(string)
	repo, _ := src["repo"].(string)
	asset, _ := src["asset"].(string)
	url, _ := o["url"].(string)
	switch {
	case repo != "" && (typ == "" || typ == "github"):
		s := "github.com/" + repo
		if asset != "" {
			s += " (" + asset + ")"
		}
		return s
	case url != "":
		return url
	case typ != "":
		return typ
	}
	return "—"
}

func CatalogDiff(before, after []byte) Diff {
	var d Diff
	var a map[string]any
	if err := json.Unmarshal(after, &a); err != nil {
		d.Bad = "This isn't a catalog (" + err.Error() + "). Don't sign it."
		return d
	}
	newApps, newRest := lists(a)
	d.Count = len(newApps)
	if len(before) == 0 {
		d.First = true
		for _, e := range newApps {
			d.Added = append(d.Added, AppLine{ID: fmt.Sprint(e.obj["id"]), Name: appName(e.obj), List: e.list, From: appFrom(e.obj)})
		}
		sortLines(d.Added)
		return d
	}
	var b map[string]any
	if json.Unmarshal(before, &b) != nil {
		b = map[string]any{}
	}
	oldApps, oldRest := lists(b)
	for k, e := range newApps {
		o, had := oldApps[k]
		if !had {
			d.Added = append(d.Added, AppLine{ID: fmt.Sprint(e.obj["id"]), Name: appName(e.obj), List: e.list, From: appFrom(e.obj)})
			continue
		}
		fo, fn := map[string]string{}, map[string]string{}
		flatten("", o.obj, fo)
		flatten("", e.obj, fn)
		ch := AppChange{ID: fmt.Sprint(e.obj["id"]), Name: appName(e.obj), List: e.list}
		pics, text := map[string]bool{}, map[string]bool{}
		for _, p := range unionKeys(fo, fn) {
			if fo[p] == fn[p] {
				continue
			}
			switch {
			case isDownloadPath(p):
				ch.Download = append(ch.Download, FieldChange{Path: p, Before: fo[p], After: fn[p]})
			case isPicturePath(p):
				pics[topKey(p)] = true
			default:
				text[topKey(p)] = true
			}
		}
		ch.Pictures, ch.Text = keys(pics), keys(text)
		if len(ch.Download)+len(ch.Pictures)+len(ch.Text) > 0 {
			d.Changed = append(d.Changed, ch)
		}
	}
	for k, e := range oldApps {
		if _, still := newApps[k]; !still {
			d.Removed = append(d.Removed, AppLine{ID: fmt.Sprint(e.obj["id"]), Name: appName(e.obj), List: e.list, From: appFrom(e.obj)})
		}
	}
	fo, fn := map[string]string{}, map[string]string{}
	flatten("", oldRest, fo)
	flatten("", newRest, fn)
	for _, p := range unionKeys(fo, fn) {
		if fo[p] != fn[p] {
			d.Settings = append(d.Settings, FieldChange{Path: p, Before: fo[p], After: fn[p]})
		}
	}
	sortLines(d.Added)
	sortLines(d.Removed)
	sort.Slice(d.Changed, func(i, j int) bool {
		if (len(d.Changed[i].Download) > 0) != (len(d.Changed[j].Download) > 0) {
			return len(d.Changed[i].Download) > 0
		}
		return strings.ToLower(d.Changed[i].Name) < strings.ToLower(d.Changed[j].Name)
	})
	sort.Slice(d.Settings, func(i, j int) bool {
		if isDownloadPath(d.Settings[i].Path) != isDownloadPath(d.Settings[j].Path) {
			return isDownloadPath(d.Settings[i].Path)
		}
		return d.Settings[i].Path < d.Settings[j].Path
	})
	return d
}

func topKey(p string) string {
	if i := strings.IndexAny(p, ".["); i > 0 {
		return p[:i]
	}
	return p
}

func unionKeys(a, b map[string]string) []string {
	m := map[string]bool{}
	for k := range a {
		m[k] = true
	}
	for k := range b {
		m[k] = true
	}
	return keys(m)
}

func keys(m map[string]bool) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	sort.Strings(out)
	return out
}

func sortLines(l []AppLine) {
	sort.Slice(l, func(i, j int) bool { return strings.ToLower(l[i].Name) < strings.ToLower(l[j].Name) })
}
