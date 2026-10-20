//go:build !windows

package main

import "log"

func openBrowser(url string)          { log.Printf("open %s", url) }
func notify(title, msg, url string)   { log.Printf("notify: %s: %s", title, msg) }
func startsWithWindows() bool         { return false }
func setStartsWithWindows(bool) error { return nil }
