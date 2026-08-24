<?php
/**
 * Copyright (C) 2026 xuanfeng0316
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

//Replace it with your server-side IP.
$api_url = 'http://yourip:6666/stats';
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200 || $response === false) {
    $data = [];
} else {
    $data = json_decode($response, true);
    if (!is_array($data)) {
        $data = [];
    }
}

$total_qps = 0;
foreach ($data as $item) {
    $total_qps += $item['qps'];
}
if ($total_qps > 0) {
    foreach ($data as &$item) {
        $item['percent'] = round($item['qps'] / $total_qps * 100, 1);
    }
    unset($item);
    usort($data, function($a, $b) {
        if ($a['percent'] != $b['percent']) {
            return $b['percent'] - $a['percent'];
        }
        return strcmp($a['ip'], $b['ip']);
    });
} else {
    $data = [];
}
?>
<!--
    Copyright (C) 2026 xuanfeng0316
    SPDX-License-Identifier: GPL-3.0-or-later
-->
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Server - ATTACK MAP</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{background:#0a0e17;overflow:hidden;font-family:monospace}
        #mapWrapper{position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:1}
        #map{width:100%;height:100%}
        #loading{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);color:#00ffff;font-size:16px;opacity:0.6;z-index:10}
        .country{fill:rgba(0,255,255,0.05);stroke:#00ffff;stroke-width:0.6;cursor:pointer}
        .country-label{fill:#00ffff;font-size:14px;pointer-events:none;text-shadow:0 0 20px rgba(0,255,255,0.8),0 0 40px rgba(0,255,255,0.4)}
        .server-marker{pointer-events:none}
        .attack-layer{pointer-events:none}

        #header {
            position: fixed;
            top: 24px;
            left: 28px;
            z-index: 50;
            font-family: monospace;
            pointer-events: none;
            user-select: none;
        }
        #header .main {
            font-size: 26px;
            font-weight: bold;
            color: #00ffff;
            letter-spacing: 2px;
            text-shadow: 0 0 30px rgba(0,255,255,0.15);
        }
        #header .sub {
            font-size: 13px;
            color: #00ffff;
            opacity: 0.5;
            letter-spacing: 4px;
            margin-top: 2px;
            padding-left: 2px;
        }

        #sidePanel{position:fixed;right:20px;top:50%;transform:translateY(-50%);background:rgba(0,0,0,0.5);border:1px solid #00ffff;border-radius:8px;padding:12px 14px;min-width:160px;max-height:80vh;overflow-y:auto;color:#00ffff;font-size:13px;z-index:100;backdrop-filter:blur(4px);box-shadow:0 0 30px rgba(0,255,255,0.1);transition:transform 0.3s ease;pointer-events:auto;max-width:calc(100vw - 40px)}
        #sidePanel::-webkit-scrollbar{width:3px}
        #sidePanel::-webkit-scrollbar-track{background:transparent}
        #sidePanel::-webkit-scrollbar-thumb{background:#00ffff;border-radius:4px}
        #sidePanel.collapsed{transform:translateY(-50%) translateX(calc(100% + 30px))}
        #panelHeader{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid rgba(0,255,255,0.3);padding-bottom:6px;margin-bottom:8px;font-weight:bold;font-size:12px;letter-spacing:1px;color:#00ffff}
        #panelHeader .closeBtn{cursor:pointer;font-size:16px;line-height:1;color:#00ffff;opacity:0.7;transition:opacity 0.2s;background:none;border:none;padding:0 4px}
        #panelHeader .closeBtn:hover{opacity:1}
        #toggleBtn{position:fixed;right:20px;top:50%;transform:translateY(-50%);background:rgba(0,0,0,0.5);border:1px solid #00ffff;border-radius:8px;color:#00ffff;padding:10px 8px;cursor:pointer;font-size:14px;z-index:100;backdrop-filter:blur(4px);display:none;box-shadow:0 0 20px rgba(0,255,255,0.08);transition:all 0.2s;pointer-events:auto}
        #toggleBtn:hover{background:rgba(0,255,255,0.1)}
        #toggleBtn.visible{display:block}
        .panelItem{display:flex;justify-content:space-between;gap:16px;padding:3px 0;border-bottom:1px solid rgba(0,255,255,0.06);font-size:12px;white-space:nowrap}
        .panelItem .ip{color:#00ffff;opacity:0.9}
        .panelItem .pct{color:#00ffff;opacity:0.7;font-weight:bold}
        .panelItem:last-child{border-bottom:none}
        .panelDisclaimer {
            font-size: 10px;
            color: #00ffff;
            opacity: 0.35;
            padding-top: 8px;
            margin-top: 6px;
            border-top: 1px solid rgba(0,255,255,0.08);
            line-height: 1.4;
            letter-spacing: 0.5px;
        }
        #zoomControls{position:fixed;bottom:30px;left:50%;transform:translateX(-50%);z-index:50;display:flex;gap:12px;background:rgba(0,0,0,0.4);padding:8px 16px;border-radius:20px;border:1px solid rgba(0,255,255,0.2);backdrop-filter:blur(4px)}
        #zoomControls button{background:transparent;border:1px solid #00ffff;color:#00ffff;padding:4px 12px;border-radius:12px;cursor:pointer;font-family:monospace;font-size:14px;transition:all 0.2s}
        #zoomControls button:hover{background:rgba(0,255,255,0.15)}
        #zoomControls span{color:#00ffff;font-size:13px;opacity:0.7;display:flex;align-items:center}

        #legend {
            position: fixed;
            bottom: 90px;
            right: 20px;
            z-index: 50;
            font-family: monospace;
            color: #00ffff;
            font-size: 12px;
            opacity: 0.6;
            text-align: right;
            pointer-events: none;
            user-select: none;
            line-height: 1.8;
            letter-spacing: 0.5px;
        }
        #legend .dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 6px;
            vertical-align: middle;
        }
        #legend .dot-red { background: #ff0000; }
        #legend .label {
            vertical-align: middle;
        }
    </style>
</head>
<body>

<!--
    Copyright (C) 2026 xuanfeng0316
    SPDX-License-Identifier: GPL-3.0-or-later
-->

    <div id="loading">Loading map data...</div>
    <div id="mapWrapper">
        <div id="map"></div>
    </div>

    <div id="header">
        <div class="main">The Server</div>
        <div class="sub">ATTACK MAP</div>
    </div>

    <div id="sidePanel">
        <div id="panelHeader">
            <span>◈ ATTACKERS</span>
            <button class="closeBtn" id="closePanel">✕</button>
        </div>
        <div id="panelList">
            <?php foreach ($data as $item): ?>
            <div class="panelItem">
                <span class="ip"><?php echo htmlspecialchars($item['ip']); ?></span>
                <span class="pct"><?php echo number_format($item['percent'], 1); ?>%</span>
            </div>
            <?php endforeach; ?>
            <?php if (empty($data)): ?>
            <div class="panelItem" style="opacity:0.4;justify-content:center">No attacks</div>
            <?php endif; ?>
        </div>
        <div class="panelDisclaimer">
            These IPs are likely compromised hosts (zombies).<br>
            For blacklist reference &amp; security statistics only.
        </div>
    </div>

    <button id="toggleBtn">[&lt;]</button>

    <div id="zoomControls">
        <button id="zoomInBtn">+</button>
        <span id="zoomLevel">100%</span>
        <button id="zoomOutBtn">−</button>
        <button id="resetViewBtn">⟲</button>
    </div>

    <div id="legend">
        <span class="dot dot-red"></span><span class="label">SSH Password Brute Force</span>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/d3@7"></script>
    <script src="https://cdn.jsdelivr.net/npm/topojson-client@3"></script>
    <script>
        var width = window.innerWidth;
        var height = window.innerHeight;

        var DOT_RADIUS = 3;
        var FLYING_DOT_RADIUS = 2;
        var TRAIL_WIDTH = 3;

        var projection = d3.geoMercator()
            .scale(150)
            .translate([width / 2, height / 1.5]);

        var path = d3.geoPath().projection(projection);

        var svg = d3.select('#map')
            .append('svg')
            .attr('width', width)
            .attr('height', height)
            .style('background', '#0a0e17');

        var g = svg.append('g');
        var labelGroup = svg.append('g');
        var markerGroup = svg.append('g').attr('class', 'server-marker');
        var attackGroup = svg.append('g').attr('class', 'attack-layer');
        // Replace with your server's coordinates
        // Format: [<lat>, <lng>]
        var serverLoc = [22.2783, 114.1747];
        var attackData = <?php echo json_encode($data); ?>;

        var attackLocs = attackData.map(function(d) {
            return [d.lat, d.lng];
        });

        var particles = [];
        var travelDuration = 0.5;
        var trailLifetime = 0.1;
        var animFrameId = null;
        var isRunning = false;
        var isPaused = false;
        var pauseTimer = null;
        var currentTransform = d3.zoomIdentity;

        function getSpeed(p1, p2) {
            var dx = p2[0] - p1[0];
            var dy = p2[1] - p1[1];
            var dist = Math.hypot(dx, dy);
            return dist / travelDuration;
        }

        function createParticle(loc, now) {
            var p1 = projection([loc[1], loc[0]]);
            var p2 = projection([serverLoc[1], serverLoc[0]]);
            if (!p1 || !p2) return null;

            var speed = getSpeed(p1, p2);
            var dx = p2[0] - p1[0];
            var dy = p2[1] - p1[1];
            var dist = Math.hypot(dx, dy);
            var vx = (dx / dist) * speed;
            var vy = (dy / dist) * speed;

            return {
                x: p1[0],
                y: p1[1],
                vx: vx,
                vy: vy,
                trail: [],
                reached: false,
                p1: p1,
                p2: p2,
                loc: loc
            };
        }

        function updateParticles(deltaTime, now) {
            for (var i = particles.length - 1; i >= 0; i--) {
                var p = particles[i];

                if (!p.reached) {
                    var prevX = p.x;
                    var prevY = p.y;
                    p.x += p.vx * deltaTime;
                    p.y += p.vy * deltaTime;
                    p.trail.push({ x: prevX, y: prevY, birth: now });
                    var dx = p.x - p.p2[0];
                    var dy = p.y - p.p2[1];
                    if (Math.hypot(dx, dy) < 5) {
                        p.reached = true;
                        p.x = p.p2[0];
                        p.y = p.p2[1];
                    }
                }

                p.trail = p.trail.filter(function(t) {
                    return now - t.birth < trailLifetime;
                });

                if (p.reached && p.trail.length === 0) {
                    particles.splice(i, 1);
                }
            }
        }

        function drawParticles() {
            attackGroup.selectAll('*').remove();

            for (var j = 0; j < attackLocs.length; j++) {
                var loc = attackLocs[j];
                var coords = projection([loc[1], loc[0]]);
                if (!coords) continue;
                attackGroup.append('circle')
                    .attr('cx', coords[0])
                    .attr('cy', coords[1])
                    .attr('r', DOT_RADIUS)
                    .attr('fill', '#ff0000')
                    .attr('opacity', 0.8);
                attackGroup.append('circle')
                    .attr('cx', coords[0])
                    .attr('cy', coords[1])
                    .attr('r', DOT_RADIUS * 2)
                    .attr('fill', 'none')
                    .attr('stroke', '#ff0000')
                    .attr('stroke-width', 2)
                    .attr('opacity', 0.3);
            }

            for (var idx = 0; idx < particles.length; idx++) {
                var p = particles[idx];
                if (p.trail.length < 2) continue;

                var d = '';
                for (var i = 0; i < p.trail.length; i++) {
                    if (i === 0) d += 'M' + p.trail[i].x + ',' + p.trail[i].y;
                    else d += 'L' + p.trail[i].x + ',' + p.trail[i].y;
                }
                d += 'L' + p.x + ',' + p.y;

                var gradId = 'grad_' + idx;
                var defs = attackGroup.select('defs');
                var defsEl = defs.empty() ? attackGroup.append('defs') : defs;

                var grad = defsEl.append('linearGradient')
                    .attr('id', gradId)
                    .attr('x1', p.trail[0].x)
                    .attr('y1', p.trail[0].y)
                    .attr('x2', p.x)
                    .attr('y2', p.y)
                    .attr('gradientUnits', 'userSpaceOnUse');

                grad.append('stop')
                    .attr('offset', '0%')
                    .attr('stop-color', '#ff2222')
                    .attr('stop-opacity', '0');

                grad.append('stop')
                    .attr('offset', '40%')
                    .attr('stop-color', '#ff4444')
                    .attr('stop-opacity', '0.4');

                grad.append('stop')
                    .attr('offset', '80%')
                    .attr('stop-color', '#ff6666')
                    .attr('stop-opacity', '0.8');

                grad.append('stop')
                    .attr('offset', '100%')
                    .attr('stop-color', '#ff0000')
                    .attr('stop-opacity', '1');

                attackGroup.append('path')
                    .attr('d', d)
                    .attr('fill', 'none')
                    .attr('stroke', 'url(#' + gradId + ')')
                    .attr('stroke-width', TRAIL_WIDTH)
                    .attr('stroke-linecap', 'round')
                    .attr('stroke-linejoin', 'round');

                attackGroup.append('circle')
                    .attr('cx', p.x)
                    .attr('cy', p.y)
                    .attr('r', FLYING_DOT_RADIUS)
                    .attr('fill', '#ff0000')
                    .attr('opacity', 1);
            }
        }

        var nextLaunchTimes = [];

        function initLaunchTimes() {
            for (var i = 0; i < attackLocs.length; i++) {
                nextLaunchTimes[i] = Math.random() * 0.5;
            }
        }
        initLaunchTimes();

        function getInterval(qps) {
            var interval = 0.3 / (qps + 0.5);
            if (interval < 0.3) interval = 0.3;
            if (interval > 3.0) interval = 3.0;
            return interval;
        }

        function pauseAnimation() {
            isPaused = true;
            if (pauseTimer) {
                clearTimeout(pauseTimer);
                pauseTimer = null;
            }
        }

        function resumeAnimation() {
            if (pauseTimer) {
                clearTimeout(pauseTimer);
                pauseTimer = null;
            }
            pauseTimer = setTimeout(function() {
                isPaused = false;
                lastTimestamp = 0;
                pauseTimer = null;
            }, 316);
        }

        function restartProcess() {
            if (animFrameId) {
                cancelAnimationFrame(animFrameId);
                animFrameId = null;
            }
            particles = [];
            initLaunchTimes();
            isRunning = true;
            isPaused = false;
            lastTimestamp = 0;
            if (pauseTimer) {
                clearTimeout(pauseTimer);
                pauseTimer = null;
            }
            requestAnimationFrame(animate);
        }

        function startProcess(timestamp) {
            if (animFrameId) {
                cancelAnimationFrame(animFrameId);
                animFrameId = null;
            }
            particles = [];
            initLaunchTimes();
            isRunning = true;
            isPaused = false;
            lastTimestamp = 0;
            if (timestamp) {
                animate(timestamp);
            } else {
                requestAnimationFrame(animate);
            }
        }

        var lastTimestamp = 0;

        function animate(timestamp) {
            if (!isRunning) {
                animFrameId = requestAnimationFrame(animate);
                return;
            }

            if (isPaused) {
                lastTimestamp = 0;
                animFrameId = requestAnimationFrame(animate);
                return;
            }

            if (!lastTimestamp) {
                lastTimestamp = timestamp;
                animFrameId = requestAnimationFrame(animate);
                return;
            }

            var deltaTime = (timestamp - lastTimestamp) / 1000;
            if (deltaTime > 0.1) deltaTime = 0.05;
            if (deltaTime < 0.001) deltaTime = 0.001;
            lastTimestamp = timestamp;

            var now = timestamp / 1000;

            for (var i = 0; i < attackLocs.length; i++) {
                var loc = attackLocs[i];
                var existing = null;
                for (var j = 0; j < particles.length; j++) {
                    if (particles[j].loc === loc) {
                        existing = particles[j];
                        break;
                    }
                }
                if (!existing && now >= nextLaunchTimes[i]) {
                    var p = createParticle(loc, now);
                    if (p) {
                        particles.push(p);
                    }
                    var qps = attackData[i].qps || 0.1;
                    var interval = getInterval(qps);
                    nextLaunchTimes[i] = now + interval + Math.random() * 0.2;
                }
            }

            updateParticles(deltaTime, now);
            drawParticles();

            animFrameId = requestAnimationFrame(animate);
        }

        function drawServerMarker() {
            var coords = projection([serverLoc[1], serverLoc[0]]);
            if (!coords) return;

            markerGroup.append('circle')
                .attr('cx', coords[0])
                .attr('cy', coords[1])
                .attr('r', DOT_RADIUS)
                .attr('fill', '#0066ff')
                .attr('stroke', 'none');
            markerGroup.append('circle')
                .attr('cx', coords[0])
                .attr('cy', coords[1])
                .attr('r', DOT_RADIUS * 1.8)
                .attr('fill', 'none')
                .attr('stroke', '#0066ff')
                .attr('stroke-width', 2)
                .attr('opacity', 0.3);
        }

        function highlightCountry(d) {
            g.selectAll('.country').style('stroke-width', '0.6px');
            labelGroup.selectAll('.country-label').remove();
            
            if (!d) return;
            
            var ids = [d.id];
            if (d.id === '156') {
                ids.push('158');
            } else if (d.id === '158') {
                ids.push('156');
            }
            
            g.selectAll('.country').filter(function(f) {
                return ids.indexOf(f.id) > -1;
            }).style('stroke-width', '2px');
            
            var centroid = path.centroid(d);
            var displayName = d.properties.name;
            if (d.id === '158') {
                displayName = 'China';
            }
            labelGroup.append('text')
                .attr('class', 'country-label')
                .attr('x', centroid[0])
                .attr('y', centroid[1] - 10)
                .attr('text-anchor', 'middle')
                .text(displayName);
        }

        var mapLoaded = false;

        fetch('countries.json')
            .then(function(res) { return res.json(); })
            .then(function(topojsonData) {
                document.getElementById('loading').style.display = 'none';
                var countries = topojson.feature(topojsonData, topojsonData.objects.countries);
                
                countries.features.forEach(function(f) {
                    if (f.id === '158') {
                        f.properties.name = 'China';
                    }
                });
                
                g.selectAll('path')
                    .data(countries.features)
                    .enter()
                    .append('path')
                    .attr('class', 'country')
                    .attr('d', path)
                    .on('mouseover', function(event, d) {
                        highlightCountry(d);
                    })
                    .on('mouseout', function() {
                        highlightCountry(null);
                    });

                drawServerMarker();
                mapLoaded = true;
                startProcess();
            })
            .catch(function(err) {
                document.getElementById('loading').textContent = 'Load failed: ' + err.message;
                console.error(err);
            });

        var zoom = d3.zoom()
            .scaleExtent([0.3, 10])
            .on('start', function() {
                pauseAnimation();
            })
            .on('zoom', function(event) {
                currentTransform = event.transform;
                g.attr('transform', currentTransform);
                labelGroup.attr('transform', currentTransform);
                attackGroup.attr('transform', currentTransform);
                markerGroup.attr('transform', currentTransform);
                document.getElementById('zoomLevel').textContent = Math.round(currentTransform.k * 100) + '%';
            })
            .on('end', function() {
                pauseAnimation();
                setTimeout(function() {
                    restartProcess();
                }, 50);
            });

        svg.call(zoom);

        document.getElementById('zoomInBtn').addEventListener('click', function() {
            pauseAnimation();
            svg.transition().duration(300).call(zoom.scaleBy, 1.4);
            setTimeout(function() {
                pauseAnimation();
                setTimeout(function() {
                    restartProcess();
                }, 50);
            }, 316);
        });

        document.getElementById('zoomOutBtn').addEventListener('click', function() {
            pauseAnimation();
            svg.transition().duration(300).call(zoom.scaleBy, 1/1.4);
            setTimeout(function() {
                pauseAnimation();
                setTimeout(function() {
                    restartProcess();
                }, 50);
            }, 316);
        });

        document.getElementById('resetViewBtn').addEventListener('click', function() {
            pauseAnimation();
            svg.transition().duration(500).call(zoom.transform, d3.zoomIdentity);
            setTimeout(function() {
                pauseAnimation();
                setTimeout(function() {
                    restartProcess();
                }, 50);
            }, 516);
        });

        var resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                if (mapLoaded) {
                    startProcess();
                }
            }, 300);
        });

        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                isRunning = false;
                if (animFrameId) {
                    cancelAnimationFrame(animFrameId);
                    animFrameId = null;
                }
            } else {
                if (mapLoaded) {
                    startProcess();
                }
            }
        });

        (function() {
            var panel = document.getElementById('sidePanel');
            var toggleBtn = document.getElementById('toggleBtn');
            var closeBtn = document.getElementById('closePanel');

            function collapsePanel() {
                panel.classList.add('collapsed');
                toggleBtn.classList.add('visible');
            }

            function expandPanel() {
                panel.classList.remove('collapsed');
                toggleBtn.classList.remove('visible');
            }

            closeBtn.addEventListener('click', collapsePanel);
            toggleBtn.addEventListener('click', expandPanel);

            var panelWidth = panel.offsetWidth;
            panel.style.minWidth = panelWidth + 'px';
        })();
    </script>
</body>
</html>