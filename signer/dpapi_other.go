//go:build !windows

package main

// Only for testing the signer on Linux: no DPAPI there, so the key is stored as it is.

func protect(data []byte) ([]byte, error) { return append([]byte(nil), data...), nil }

func unprotect(data []byte) ([]byte, error) { return append([]byte(nil), data...), nil }
