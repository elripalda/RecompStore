//go:build windows

package main

import (
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"
)

func hidden(cmd *exec.Cmd) *exec.Cmd {
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true, CreationFlags: 0x08000000}
	return cmd
}

func openBrowser(url string) {
	_ = hidden(exec.Command("rundll32", "url.dll,FileProtocolHandler", url)).Start()
}

func psQuote(s string) string { return "'" + strings.ReplaceAll(s, "'", "''") + "'" }

func xmlEscape(s string) string {
	r := strings.NewReplacer("&", "&amp;", "<", "&lt;", ">", "&gt;", "\"", "&quot;", "'", "&apos;")
	return r.Replace(s)
}

// A Windows notification; clicking it opens the signer's page.
func notify(title, msg, url string) {
	xml := `<toast activationType="protocol" launch="` + xmlEscape(url) + `"><visual><binding template="ToastGeneric"><text>` +
		xmlEscape(title) + `</text><text>` + xmlEscape(msg) + `</text></binding></visual></toast>`
	script := `[Windows.UI.Notifications.ToastNotificationManager, Windows.UI.Notifications, ContentType = WindowsRuntime] > $null;` +
		`[Windows.Data.Xml.Dom.XmlDocument, Windows.Data.Xml.Dom.XmlDocument, ContentType = WindowsRuntime] > $null;` +
		`$d = New-Object Windows.Data.Xml.Dom.XmlDocument; $d.LoadXml(` + psQuote(xml) + `);` +
		`$t = New-Object Windows.UI.Notifications.ToastNotification $d;` +
		`[Windows.UI.Notifications.ToastNotificationManager]::CreateToastNotifier('{1AC14E77-02E7-4E5D-B744-2EB1AE5198B7}\WindowsPowerShell\v1.0\powershell.exe').Show($t)`
	_ = hidden(exec.Command("powershell", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-Command", script)).Run()
}

func startupShortcut() string {
	return filepath.Join(os.Getenv("APPDATA"), `Microsoft\Windows\Start Menu\Programs\Startup\Recomp Store Signer.lnk`)
}

func startsWithWindows() bool {
	_, err := os.Stat(startupShortcut())
	return err == nil
}

func setStartsWithWindows(on bool) error {
	if !on {
		err := os.Remove(startupShortcut())
		if os.IsNotExist(err) {
			return nil
		}
		return err
	}
	exe, err := os.Executable()
	if err != nil {
		return err
	}
	script := `$s = (New-Object -ComObject WScript.Shell).CreateShortcut(` + psQuote(startupShortcut()) + `);` +
		`$s.TargetPath = ` + psQuote(exe) + `; $s.Arguments = '--background'; $s.WorkingDirectory = ` + psQuote(filepath.Dir(exe)) + `; $s.Save()`
	return hidden(exec.Command("powershell", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-Command", script)).Run()
}
