package main

import (
	"strings"
	"testing"
)

const sampleMountinfo = `1290 1201 0:345 / / ro,relatime master:1 - overlay overlay rw,lowerdir=/x
1291 1290 0:347 / /proc rw,nosuid,nodev,noexec,relatime - proc proc rw
1300 1290 8:1 /var/lib/pelican/volumes/abc /home/container rw,relatime - ext4 /dev/sda1 rw
1301 1290 8:1 /var/lib/pelican/volumes/def /server rw,relatime - ext4 /dev/sda1 rw
1302 1290 8:1 /x /with\040space rw,relatime - ext4 /dev/sda1 rw
`

func TestMounted(t *testing.T) {
	if !mounted(strings.NewReader(sampleMountinfo), "/server") {
		t.Fatal("/server not found")
	}
	if !mounted(strings.NewReader(sampleMountinfo), "/with space") {
		t.Fatal("escaped path not decoded")
	}
	if mounted(strings.NewReader(sampleMountinfo), "/serv") {
		t.Fatal("prefix matched")
	}

	without := strings.ReplaceAll(sampleMountinfo, " /server ", " /elsewhere ")
	if mounted(strings.NewReader(without), "/server") {
		t.Fatal("found a mount that isn't there")
	}
}

func TestUnescapeMountPath(t *testing.T) {
	for in, want := range map[string]string{
		`/plain`:        "/plain",
		`/a\040b`:       "/a b",
		`/tab\011x`:     "/tab\tx",
		`/back\134s`:    `/back\s`,
		`/trailing\04`:  `/trailing\04`,
		`/notoctal\09x`: `/notoctal\09x`,
	} {
		if got := unescapeMountPath(in); got != want {
			t.Errorf("unescapeMountPath(%q) = %q, want %q", in, got, want)
		}
	}
}
