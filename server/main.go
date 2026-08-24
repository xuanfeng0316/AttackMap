/*
 * Copyright (C) 2026 xuanfeng0316
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

package main

import (
	"bufio"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"os/exec"
	"runtime"
	"strings"
	"sync"
	"time"
)

const (
	LOG_PATH         = "/var/log/auth.log"
	CACHE_FILE       = "IPLocation.json"
	PENDING_FILE     = "pending_ips.json"
	CACHE_TTL        = 1800
	STATS_INTERVAL   = 60
	PORT             = "6666"
	MAX_MEMORY_MB    = 45
	RETRY_DELAY      = 60
	MAX_RETRY        = 5
	REQUEST_INTERVAL = 1500
)

type LocationCache struct {
	Lat     float64 `json:"lat"`
	Lng     float64 `json:"lng"`
	Updated int64   `json:"updated"`
	Retries int     `json:"retries"`
}

type AttackStat struct {
	IP  string  `json:"ip"`
	Lat float64 `json:"lat"`
	Lng float64 `json:"lng"`
	QPS float64 `json:"qps"`
}

type PendingIP struct {
	IP      string `json:"ip"`
	Retries int    `json:"retries"`
	AddedAt int64  `json:"added_at"`
}

var (
	cache        = make(map[string]LocationCache)
	cacheMutex   sync.RWMutex
	stats        = []AttackStat{}
	statsMutex   sync.RWMutex
	pending      = make(map[string]PendingIP)
	pendingMutex sync.Mutex
	rateLimiter  = time.NewTicker(REQUEST_INTERVAL * time.Millisecond)
	httpClient   = &http.Client{
		Timeout: 5 * time.Second,
		Transport: &http.Transport{
			Dial: (&net.Dialer{
				Timeout:   3 * time.Second,
				KeepAlive: 30 * time.Second,
			}).Dial,
			TLSHandshakeTimeout:   3 * time.Second,
			ResponseHeaderTimeout: 3 * time.Second,
			ExpectContinueTimeout: 1 * time.Second,
		},
	}
	logger *log.Logger
)

func init() {
	logger = log.New(os.Stdout, "[ATTACK-SERVER] ", log.LstdFlags)
}

func parseAuthLog() map[string]int {
	logger.Printf("parsing auth log: %s", LOG_PATH)
	cmd := exec.Command("sh", "-c",
		"grep 'Failed password' /var/log/auth.log | tail -1000 | awk '{print $(NF-3)}' | sort | uniq -c | awk '{print $2\" \"$1}'")

	output, err := cmd.Output()
	if err != nil {
		logger.Printf("parse failed: %v", err)
		return map[string]int{}
	}

	result := make(map[string]int)
	scanner := bufio.NewScanner(strings.NewReader(string(output)))
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" {
			continue
		}
		parts := strings.Fields(line)
		if len(parts) >= 2 {
			count := 0
			fmt.Sscanf(parts[1], "%d", &count)
			if count > 0 {
				result[parts[0]] = count
			}
		}
	}
	logger.Printf("parsed %d unique IPs", len(result))
	return result
}

func loadCache() {
	cacheMutex.Lock()
	defer cacheMutex.Unlock()
	data, err := os.ReadFile(CACHE_FILE)
	if err != nil {
		if !os.IsNotExist(err) {
			logger.Printf("load cache error: %v", err)
		}
		return
	}
	if err := json.Unmarshal(data, &cache); err != nil {
		logger.Printf("parse cache error: %v", err)
		return
	}
	logger.Printf("loaded %d cache entries", len(cache))
}

func saveCache() {
	cacheMutex.RLock()
	defer cacheMutex.RUnlock()
	data, err := json.MarshalIndent(cache, "", "  ")
	if err != nil {
		logger.Printf("serialize cache error: %v", err)
		return
	}
	if err := os.WriteFile(CACHE_FILE, data, 0644); err != nil {
		logger.Printf("write cache error: %v", err)
		return
	}
	logger.Printf("cache saved")
}

func loadPending() {
	pendingMutex.Lock()
	defer pendingMutex.Unlock()
	data, err := os.ReadFile(PENDING_FILE)
	if err != nil {
		if !os.IsNotExist(err) {
			logger.Printf("load pending error: %v", err)
		}
		return
	}
	var list []PendingIP
	if err := json.Unmarshal(data, &list); err != nil {
		logger.Printf("parse pending error: %v", err)
		return
	}
	pending = make(map[string]PendingIP)
	for _, p := range list {
		pending[p.IP] = p
	}
	logger.Printf("loaded %d pending IPs", len(pending))
}

func savePending() {
	list := make([]PendingIP, 0, len(pending))
	for _, p := range pending {
		list = append(list, p)
	}
	data, err := json.MarshalIndent(list, "", "  ")
	if err != nil {
		logger.Printf("serialize pending error: %v", err)
		return
	}
	if err := os.WriteFile(PENDING_FILE, data, 0644); err != nil {
		logger.Printf("write pending error: %v", err)
		return
	}
	logger.Printf("pending saved")
}

func getLocation(ip string) (float64, float64, bool) {
	now := time.Now().Unix()

	cacheMutex.RLock()
	entry, exists := cache[ip]
	cacheMutex.RUnlock()
	if exists {
		if now-entry.Updated < CACHE_TTL {
			return entry.Lat, entry.Lng, true
		}
		cacheMutex.Lock()
		delete(cache, ip)
		cacheMutex.Unlock()
	}

	pendingMutex.Lock()
	p, isPending := pending[ip]
	if !isPending {
		p = PendingIP{IP: ip, Retries: 0, AddedAt: now}
		pending[ip] = p
		pendingMutex.Unlock()
		savePending()
		pendingMutex.Lock()
	} else if p.Retries >= MAX_RETRY {
		delete(pending, ip)
		pendingMutex.Unlock()
		savePending()
		return 0, 0, false
	}
	pendingMutex.Unlock()

	if time.Now().Unix()-p.AddedAt < RETRY_DELAY {
		return 0, 0, false
	}

	<-rateLimiter.C

	url := fmt.Sprintf("https://ipinfo.io/%s", ip)
	req, err := http.NewRequest("GET", url, nil)
	if err != nil {
		logger.Printf("request failed for %s: %v", ip, err)
		return 0, 0, false
	}
	req.Header.Set("User-Agent", "curl/8.5.0")
	req.Header.Set("Accept", "*/*")

	ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
	defer cancel()
	req = req.WithContext(ctx)

	resp, err := httpClient.Do(req)
	if err != nil {
		logger.Printf("http error for %s: %v", ip, err)
		pendingMutex.Lock()
		if p, ok := pending[ip]; ok {
			p.Retries++
			pending[ip] = p
		}
		pendingMutex.Unlock()
		savePending()
		return 0, 0, false
	}
	defer resp.Body.Close()

	if resp.StatusCode != 200 {
		if resp.StatusCode == 429 {
			pendingMutex.Lock()
			if p, ok := pending[ip]; ok {
				p.Retries++
				p.AddedAt = time.Now().Unix()
				pending[ip] = p
			}
			pendingMutex.Unlock()
			savePending()
			return 0, 0, false
		}
		pendingMutex.Lock()
		delete(pending, ip)
		pendingMutex.Unlock()
		savePending()
		return 0, 0, false
	}

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		logger.Printf("read body failed for %s: %v", ip, err)
		return 0, 0, false
	}

	var data struct {
		Loc string `json:"loc"`
	}
	if err := json.Unmarshal(body, &data); err != nil {
		logger.Printf("json parse failed for %s: %v", ip, err)
		return 0, 0, false
	}

	if data.Loc == "" {
		pendingMutex.Lock()
		delete(pending, ip)
		pendingMutex.Unlock()
		savePending()
		return 0, 0, false
	}

	var lat, lng float64
	fmt.Sscanf(data.Loc, "%f,%f", &lat, &lng)
	if lat == 0 && lng == 0 {
		pendingMutex.Lock()
		delete(pending, ip)
		pendingMutex.Unlock()
		savePending()
		return 0, 0, false
	}

	cacheMutex.Lock()
	cache[ip] = LocationCache{Lat: lat, Lng: lng, Updated: now}
	cacheMutex.Unlock()

	pendingMutex.Lock()
	delete(pending, ip)
	pendingMutex.Unlock()
	savePending()

	return lat, lng, true
}

func cleanupCache() {
	cacheMutex.Lock()
	defer cacheMutex.Unlock()
	now := time.Now().Unix()
	toDelete := []string{}
	for ip, entry := range cache {
		if now-entry.Updated >= CACHE_TTL {
			toDelete = append(toDelete, ip)
		}
	}
	for _, ip := range toDelete {
		delete(cache, ip)
	}
	if len(toDelete) > 0 {
		logger.Printf("cleaned %d expired cache entries", len(toDelete))
	}
}

func cleanupPending() {
	pendingMutex.Lock()
	defer pendingMutex.Unlock()
	now := time.Now().Unix()
	toDelete := []string{}
	for ip, p := range pending {
		if p.Retries >= MAX_RETRY || now-p.AddedAt > 3600 {
			toDelete = append(toDelete, ip)
		}
	}
	for _, ip := range toDelete {
		delete(pending, ip)
	}
	if len(toDelete) > 0 {
		logger.Printf("cleaned %d pending entries", len(toDelete))
		pendingMutex.Unlock()
		savePending()
		pendingMutex.Lock()
	}
}

func updateStats() {
	logger.Printf("updating stats...")
	ipCounts := parseAuthLog()

	now := time.Now().Unix()
	newStats := []AttackStat{}
	successCount := 0
	failCount := 0

	for ip, count := range ipCounts {
		qps := float64(count) / 30.0
		lat, lng, ok := getLocation(ip)
		if !ok {
			failCount++
			continue
		}
		successCount++
		newStats = append(newStats, AttackStat{
			IP:  ip,
			Lat: lat,
			Lng: lng,
			QPS: qps,
		})
	}

	logger.Printf("stats updated: %d success, %d failed", successCount, failCount)

	cleanupCache()
	cleanupPending()

	statsMutex.Lock()
	stats = newStats
	statsMutex.Unlock()

	var m runtime.MemStats
	runtime.ReadMemStats(&m)
	usedMB := m.Alloc / 1024 / 1024

	if usedMB > MAX_MEMORY_MB {
		logger.Printf("memory usage %dMB, cleaning up", usedMB)
		cacheMutex.Lock()
		toDelete := []string{}
		for ip, entry := range cache {
			if now-entry.Updated < 300 {
				continue
			}
			toDelete = append(toDelete, ip)
		}
		for _, ip := range toDelete {
			delete(cache, ip)
		}
		cacheMutex.Unlock()
		runtime.GC()
		saveCache()
	}
}

func statsLoop() {
	loadCache()
	loadPending()

	ticker := time.NewTicker(STATS_INTERVAL * time.Second)
	updateStats()

	for range ticker.C {
		updateStats()
	}
}

func handleStats(w http.ResponseWriter, r *http.Request) {
	statsMutex.RLock()
	data := make([]AttackStat, len(stats))
	copy(data, stats)
	statsMutex.RUnlock()

	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Access-Control-Allow-Origin", "*")
	json.NewEncoder(w).Encode(data)
}

func handleHealth(w http.ResponseWriter, r *http.Request) {
	statsMutex.RLock()
	count := len(stats)
	statsMutex.RUnlock()

	pendingMutex.Lock()
	pendingCount := len(pending)
	pendingMutex.Unlock()

	cacheMutex.RLock()
	cacheCount := len(cache)
	cacheMutex.RUnlock()

	response := map[string]interface{}{
		"status":       "ok",
		"active_ips":   count,
		"pending_ips":  pendingCount,
		"cache_ips":    cacheCount,
		"port":         PORT,
		"memory_limit": MAX_MEMORY_MB,
	}

	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Access-Control-Allow-Origin", "*")
	json.NewEncoder(w).Encode(response)
}

func main() {
	logger.Printf("server starting on port %s", PORT)
	go statsLoop()

	http.HandleFunc("/stats", handleStats)
	http.HandleFunc("/health", handleHealth)

	listener, err := net.Listen("tcp", ":"+PORT)
	if err != nil {
		logger.Fatalf("listen failed: %v", err)
	}

	server := &http.Server{Handler: nil}
	if err := server.Serve(listener); err != nil {
		logger.Fatalf("serve failed: %v", err)
	}
}