/* =========================================================================
   BIOME ENTERPRISES — "THE JOURNEY"
   Scroll-scrubbed truck journey: Forest → Loading → Highway → Bridge/River →
   Mountains → Village → Warehouse. Pure SVG + CSS transforms + GSAP.

   Requires (loaded before this file):
     gsap.min.js
     ScrollTrigger.min.js
   ========================================================================= */
(function () {
  'use strict';

  var SVG_NS = 'http://www.w3.org/2000/svg';
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var isCoarsePointer = window.matchMedia('(pointer: coarse)').matches;
  var isSmall = window.innerWidth < 768;
  var lite = isCoarsePointer || isSmall; // trims particle-heavy layers on phones

  if (lite) document.documentElement.classList.add('bj-lite');

  // ---- tiny helpers -------------------------------------------------------
  function el(tag, attrs, parent) {
    var node = document.createElementNS(SVG_NS, tag);
    for (var k in attrs) if (attrs.hasOwnProperty(k)) node.setAttribute(k, attrs[k]);
    if (parent) parent.appendChild(node);
    return node;
  }
  function rand(min, max) { return min + Math.random() * (max - min); }
  function pick(arr) { return arr[Math.floor(Math.random() * arr.length)]; }
  function $(id) { return document.getElementById(id); }

  // ---- bamboo stalk generator ---------------------------------------------
  var BAMBOO_COLORS = ['var(--bj-bamboo-1)', 'var(--bj-bamboo-2)', 'var(--bj-bamboo-3)'];

  function buildBambooStalk(parent, x, groundY, height, thickness) {
    var g = el('g', { class: 'bj-bamboo', style: 'transform-origin:' + x + 'px ' + groundY + 'px;' }, parent);
    var color = pick(BAMBOO_COLORS);
    var segs = Math.max(4, Math.round(height / 46));
    var segH = height / segs;
    var stalk = el('g', { class: 'stalk' }, g);
    for (var i = 0; i < segs; i++) {
      var y = groundY - height + i * segH;
      el('rect', {
        x: x - thickness / 2, y: y, width: thickness, height: segH + 2,
        rx: thickness / 2, fill: color
      }, stalk);
      if (i > 0) {
        el('ellipse', { cx: x, cy: y, rx: thickness / 2 + 1.5, ry: 2.4, fill: 'var(--bj-bamboo-node)' }, stalk);
      }
    }
    // leaf cluster near the top
    var leaves = el('g', { class: 'bj-leaf-cluster', style: 'transform-origin:' + x + 'px ' + (groundY - height) + 'px;' }, g);
    var leafCount = 5;
    for (var l = 0; l < leafCount; l++) {
      var ang = rand(-55, 55);
      var len = rand(26, 46);
      var ly = groundY - height + rand(0, height * 0.35);
      var rad = ang * Math.PI / 180;
      var lx2 = x + Math.sin(rad) * len;
      var ly2 = ly - Math.cos(rad) * len;
      el('path', {
        d: 'M ' + x + ' ' + ly + ' Q ' + (x + Math.sin(rad) * len * 0.5 + 6) + ' ' + (ly - Math.cos(rad) * len * 0.5) + ' ' + lx2 + ' ' + ly2,
        stroke: color, 'stroke-width': 3.4, fill: 'none', 'stroke-linecap': 'round', opacity: .92
      }, leaves);
    }
    return g;
  }

  function buildBambooCluster(parent, xStart, xEnd, groundY, density) {
    var n = Math.round((xEnd - xStart) / density);
    var frag = document.createDocumentFragment ? null : null; // svg needs real nodes
    var stalks = [];
    for (var i = 0; i < n; i++) {
      var x = rand(xStart, xEnd);
      var h = rand(150, 340);
      var t = rand(7, 13);
      stalks.push(buildBambooStalk(parent, x, groundY, h, t));
    }
    return stalks;
  }

  // ---- birds ----------------------------------------------------------------
  function buildBird(parent, x, y, scale) {
    var g = el('g', { transform: 'translate(' + x + ',' + y + ') scale(' + scale + ')' }, parent);
    el('path', { d: 'M -10 0 Q -5 -8 0 0 Q 5 -8 10 0', stroke: '#2c2c2c', 'stroke-width': 2, fill: 'none', 'stroke-linecap': 'round' }, g);
    return g;
  }

  // ---- clouds ----------------------------------------------------------------
  function buildClouds() {
    var svg = $('bjClouds');
    svg.setAttribute('viewBox', '0 0 2600 300');
    var count = lite ? 3 : 6;
    for (var i = 0; i < count; i++) {
      var x = rand(0, 2600), y = rand(20, 160), s = rand(.6, 1.3);
      var g = el('g', { class: 'bj-cloud', transform: 'translate(' + x + ',' + y + ') scale(' + s + ')' }, svg);
      el('ellipse', { cx: 0, cy: 20, rx: 55, ry: 22, fill: '#fff', opacity: .9 }, g);
      el('ellipse', { cx: 40, cy: 10, rx: 40, ry: 26, fill: '#fff', opacity: .9 }, g);
      el('ellipse', { cx: -38, cy: 14, rx: 36, ry: 20, fill: '#fff', opacity: .85 }, g);
    }
    return svg;
  }

  // ---- mountains / far trees ---------------------------------------------
  function buildMountains() {
    var svg = $('bjMountains');
    svg.setAttribute('viewBox', '0 0 3200 400');
    var pts = 'M 0 400 ';
    var x = 0;
    while (x < 3200) {
      var peak = rand(90, 230);
      pts += 'L ' + (x + rand(60, 140)) + ' ' + (400 - peak) + ' ';
      x += rand(140, 260);
      pts += 'L ' + x + ' ' + rand(260, 340) + ' ';
    }
    pts += 'L 3200 400 Z';
    el('path', { d: pts, fill: 'var(--bj-mountain-far)', opacity: .55 }, svg);
    return svg;
  }

  function buildTreesFar() {
    var svg = $('bjTreesFar');
    svg.setAttribute('viewBox', '0 0 3200 260');
    var x = 0;
    while (x < 3200) {
      var h = rand(60, 130);
      var w = rand(46, 80);
      el('path', {
        d: 'M ' + x + ' 260 L ' + (x + w / 2) + ' ' + (260 - h) + ' L ' + (x + w) + ' 260 Z',
        fill: 'var(--bj-mountain-mid)', opacity: .6
      }, svg);
      x += rand(30, 70);
    }
    return svg;
  }

  function buildGrassForeground() {
    var svg = $('bjGrassFore');
    svg.setAttribute('viewBox', '0 0 3200 90');
    var grad = el('linearGradient', { id: 'bjGrassGrad', x1: 0, y1: 0, x2: 0, y2: 1 }, svg);
    el('stop', { offset: 0, 'stop-color': '#2f5122' }, grad);
    el('stop', { offset: 1, 'stop-color': '#1c3315' }, grad);
    var x = 0, path = 'M 0 90 ';
    while (x < 3200) {
      var h = rand(18, 42);
      path += 'L ' + (x + 6) + ' ' + (90 - h) + ' L ' + (x + 12) + ' 90 ';
      x += 12;
    }
    path += 'Z';
    el('path', { d: path, fill: 'url(#bjGrassGrad)' }, svg);
    return svg;
  }

  // ---- road dashes -----------------------------------------------------------
  function buildRoadLines() {
    var g = $('bjRoadLines');
    var x = 40;
    while (x < 6150) {
      el('rect', { x: x, y: 758, width: 70, height: 8, rx: 4, fill: '#f4e7b8', opacity: .5 }, g);
      x += 150;
    }
  }

  // ---- SCENE 1: forest + loading dock -------------------------------------
  function buildSceneForest() {
    var g = $('bjSceneForest');
    var groundY = 690;

    // sunlight rays
    var rays = el('g', { opacity: .35 }, g);
    for (var r = 0; r < 5; r++) {
      el('path', {
        d: 'M ' + (140 + r * 70) + ' 0 L ' + (180 + r * 70) + ' 0 L ' + (60 + r * 90) + ' 640 L ' + (10 + r * 90) + ' 640 Z',
        fill: '#fff3c9'
      }, rays);
    }

    // background dense bamboo (taller, darker, further back)
    buildBambooCluster(g, 20, 1200, groundY - 10, 46);
    // foreground bamboo (bigger/closer)
    buildBambooCluster(g, 60, 1150, groundY + 40, 70);

    // ambient particles
    var particles = el('g', { id: 'bjForestParticles', opacity: .5 }, g);
    for (var p = 0; p < 18; p++) {
      el('circle', { cx: rand(0, 1200), cy: rand(300, 680), r: rand(1, 2.6), fill: '#fff3c9' }, particles);
    }

    // birds
    buildBird(g, 260, 140, 1);
    buildBird(g, 320, 120, .8);
    buildBird(g, 940, 90, .9);

    // loading dock platform marker near where the truck sits (world x ≈ 380–760)
    el('rect', { x: 360, y: groundY + 26, width: 420, height: 10, rx: 3, fill: '#5b4a2e', opacity: .6 }, g);
  }

  // ---- SCENE 2: open highway roadside bamboo -------------------------------
  function buildSceneHighway1() {
    var g = $('bjSceneHighway1');
    var groundY = 690;
    buildBambooCluster(g, 1350, 2150, groundY + 30, 130);
    // simple farmland strip
    el('rect', { x: 1300, y: groundY - 4, width: 900, height: 34, fill: '#7c8f4a', opacity: .5 }, g);
  }

  // ---- SCENE 3: bridge + river ----------------------------------------------
  function buildSceneBridge() {
    var g = $('bjSceneBridge');
    var groundY = 690;
    var riverX = 2420, riverW = 260;

    // river bed cut into ground
    el('rect', { x: riverX, y: groundY - 30, width: riverW, height: 260, fill: 'var(--bj-river)' }, g);
    var waveG = el('g', { opacity: .5 }, g);
    for (var w = 0; w < 8; w++) {
      el('path', {
        d: 'M ' + riverX + ' ' + (groundY - 10 + w * 26) + ' q 30 -10 60 0 t 60 0 t 60 0 t 60 0',
        stroke: '#e8faff', 'stroke-width': 2.5, fill: 'none'
      }, waveG);
    }

    // bridge deck + trusses
    var deckY = groundY - 46;
    el('rect', { x: riverX - 40, y: deckY, width: riverW + 80, height: 18, rx: 3, fill: '#6a5638' }, g);
    var trussG = el('g', { stroke: '#4a3c22', 'stroke-width': 6 }, g);
    for (var t = 0; t < 6; t++) {
      var tx = riverX - 30 + t * (riverW + 60) / 5;
      el('line', { x1: tx, y1: deckY, x2: tx + 34, y2: deckY - 70 }, trussG);
      el('line', { x1: tx + 34, y1: deckY - 70, x2: tx + 68, y2: deckY }, trussG);
    }
    el('line', { x1: riverX - 40, y1: deckY - 70, x2: riverX + riverW + 40, y2: deckY - 70, stroke: '#4a3c22', 'stroke-width': 5 }, g);
    // pillars into the water
    el('rect', { x: riverX + 10, y: deckY + 14, width: 16, height: 90, fill: '#584838' }, g);
    el('rect', { x: riverX + riverW - 26, y: deckY + 14, width: 16, height: 90, fill: '#584838' }, g);

    // extend road surface to bridge deck height with a smooth ramp either side
    el('path', { d: 'M ' + (riverX - 80) + ' ' + groundY + ' L ' + (riverX - 40) + ' ' + deckY + ' L ' + (riverX - 40) + ' ' + (deckY + 18) + ' L ' + (riverX - 80) + ' ' + groundY + ' Z', fill: '#3a3630' }, g);
    el('path', { d: 'M ' + (riverX + riverW + 80) + ' ' + groundY + ' L ' + (riverX + riverW + 40) + ' ' + deckY + ' L ' + (riverX + riverW + 40) + ' ' + (deckY + 18) + ' L ' + (riverX + riverW + 80) + ' ' + groundY + ' Z', fill: '#3a3630' }, g);

    // roadside bamboo either side of the bridge
    buildBambooCluster(g, 2180, 2400, groundY + 20, 150);
    buildBambooCluster(g, 2700, 2950, groundY + 20, 150);
  }

  // ---- SCENE 4: mountain highway --------------------------------------------
  function buildSceneMountainRoad() {
    var g = $('bjSceneMountainRoad');
    var groundY = 690;
    // near mountain cutouts (closer / darker than the parallax far layer)
    var mtn = el('g', { opacity: .8 }, g);
    var x = 3000;
    while (x < 4200) {
      var peak = rand(160, 320);
      el('path', {
        d: 'M ' + x + ' ' + groundY + ' L ' + (x + 140) + ' ' + (groundY - peak) + ' L ' + (x + 280) + ' ' + groundY + ' Z',
        fill: 'var(--bj-mountain-mid)'
      }, mtn);
      x += rand(180, 260);
    }
    buildBambooCluster(g, 3050, 4150, groundY + 30, 170);
  }

  // ---- SCENE 5: village ------------------------------------------------------
  function buildSceneVillage() {
    var g = $('bjSceneVillage');
    var groundY = 690;
    var x = 4260;
    while (x < 4980) {
      var w = rand(80, 130), h = rand(70, 110);
      var houseG = el('g', {}, g);
      el('rect', { x: x, y: groundY - h, width: w, height: h, fill: '#8a6a45' }, houseG);
      el('path', { d: 'M ' + (x - 8) + ' ' + (groundY - h) + ' L ' + (x + w / 2) + ' ' + (groundY - h - 40) + ' L ' + (x + w + 8) + ' ' + (groundY - h) + ' Z', fill: '#5b3f26' }, houseG);
      el('rect', { x: x + w * .3, y: groundY - h * .5, width: w * .18, height: h * .5, fill: '#3a2c18' }, houseG);
      x += w + rand(30, 70);
    }
    buildBambooCluster(g, 4300, 4950, groundY + 34, 220);
  }

  // ---- SCENE 6: warehouse -----------------------------------------------------
  function buildSceneWarehouse() {
    var g = $('bjSceneWarehouse');
    var groundY = 690;
    var wx = 5350, ww = 720, wh = 340;

    // building shell
    el('rect', { x: wx, y: groundY - wh, width: ww, height: wh, fill: 'var(--bj-warehouse)' }, g);
    el('path', { d: 'M ' + (wx - 20) + ' ' + (groundY - wh) + ' L ' + (wx + ww / 2) + ' ' + (groundY - wh - 90) + ' L ' + (wx + ww + 20) + ' ' + (groundY - wh) + ' Z', fill: 'var(--bj-warehouse-dk)' }, g);

    // window row (lights)
    var lightsG = el('g', {}, g);
    for (var i = 0; i < 8; i++) {
      el('rect', {
        class: 'bj-warehouse-light', id: 'bjWLight' + i,
        x: wx + 40 + i * 82, y: groundY - wh + 46, width: 46, height: 34, rx: 3, fill: '#ffe9a0'
      }, lightsG);
    }

    // signage
    var sign = el('text', {
      x: wx + ww / 2, y: groundY - wh - 100, 'text-anchor': 'middle',
      'font-family': 'Roboto, sans-serif', 'font-weight': '800', 'font-size': '30',
      fill: '#271e01', opacity: .85
    }, g);
    sign.textContent = 'BIOME WAREHOUSE';

    // roller gate (animated open by JS/GSAP)
    var gateW = 190, gateX = wx + 70;
    el('rect', { x: gateX, y: groundY - wh, width: gateW, height: wh - 6, fill: '#2e2a22' }, g); // dark opening behind gate
    var gate = el('rect', {
      class: 'bj-gate-door', id: 'bjGateDoor',
      x: gateX, y: groundY - wh, width: gateW, height: wh - 6, fill: '#c7ccd3',
      style: 'transform-origin:' + (gateX + gateW / 2) + 'px ' + (groundY - wh) + 'px;'
    }, g);
    for (var s = 1; s < 8; s++) {
      el('line', { x1: gateX, y1: groundY - wh + s * (wh - 6) / 8, x2: gateX + gateW, y2: groundY - wh + s * (wh - 6) / 8, stroke: '#9aa0aa', 'stroke-width': 2 }, g);
    }

    // interior floor + forklift track
    el('rect', { x: gateX, y: groundY - 4, width: gateW, height: 8, fill: '#4a4a4a', opacity: .4 }, g);

    // forklift (independent idle animation, added later in JS)
    var fork = el('g', { class: 'bj-forklift', id: 'bjForklift', transform: 'translate(' + (gateX + 40) + ',' + (groundY - 46) + ')' }, g);
    el('rect', { x: 0, y: 0, width: 46, height: 30, rx: 4, fill: '#ffb300' }, fork);
    el('rect', { x: 6, y: -18, width: 30, height: 20, rx: 3, fill: '#333' }, fork);
    el('circle', { cx: 10, cy: 34, r: 8, fill: '#1a1a1a' }, fork);
    el('circle', { cx: 38, cy: 34, r: 8, fill: '#1a1a1a' }, fork);
    el('rect', { x: 44, y: -6, width: 5, height: 40, fill: '#8a8a8a' }, fork);

    // stacked bamboo inventory inside warehouse
    buildBambooCluster(g, gateX + gateW + 40, wx + ww - 30, groundY - 6, 55);

    // loading apron outside gate (where truck stops)
    el('rect', { x: gateX - 260, y: groundY + 26, width: 300, height: 10, rx: 3, fill: '#5b4a2e', opacity: .6 }, g);
  }

  // ---- worker figures (loading + unloading crew) ---------------------------
  function buildWorkers() {
    var host = $('bjWorkers');
    host.style.position = 'absolute';
    host.style.inset = '0';
    var svg = el('svg', { width: '100%', height: '100%', viewBox: '0 0 100 100', preserveAspectRatio: 'none' });
    svg.style.position = 'absolute';
    svg.style.inset = '0';
    svg.setAttribute('overflow', 'visible');
    host.appendChild(svg);

    // Positioned in *screen* percentage space, near the truck anchor.
    var spots = [
      { x: 20, y: 78 }, { x: 26, y: 80 }, { x: 33, y: 79 }
    ];
    spots.forEach(function (spot, i) {
      var g = el('g', {
        class: 'bj-worker', id: 'bjWorker' + i,
        transform: 'translate(' + spot.x + ',' + spot.y + ') scale(.16)',
        style: 'transform-box: fill-box;'
      }, svg);
      el('circle', { cx: 0, cy: -34, r: 7, fill: '#e7b98a' }, g);
      el('rect', { x: -8, y: -27, width: 16, height: 22, rx: 3, fill: '#3f6b32' }, g);
      el('rect', { x: -8, y: -5, width: 6, height: 18, rx: 2, fill: '#271e01' }, g);
      el('rect', { x: 2, y: -5, width: 6, height: 18, rx: 2, fill: '#271e01' }, g);
      el('rect', { x: -14, y: -24, width: 8, height: 16, rx: 3, fill: '#e7b98a' }, g);
    });
  }

  // ---- bamboo bundles inside the truck bed ----------------------------------
  function buildBundles() {
    var bay = $('bjBundleBay');
    var bundles = [];
    for (var i = 0; i < 4; i++) {
      var g = el('g', {
        class: 'bj-bundle', id: 'bjBundle' + i,
        transform: 'translate(' + (-40 - i * 10) + ',0)', opacity: 0
      }, bay);
      var by = 66 + (i % 2) * 30;
      for (var j = 0; j < 5; j++) {
        el('rect', { x: 20 + i * 62 + j * 4, y: by - j * 1.2, width: 3.4, height: 60, rx: 1.6, fill: pick(BAMBOO_COLORS) }, g);
      }
      el('ellipse', { cx: 34 + i * 62, cy: by - 2, rx: 12, ry: 4, fill: '#5b4a2e' }, g);
      bundles.push(g);
    }
    return bundles;
  }

  // ---- master build -----------------------------------------------------------
  function buildAll() {
    buildClouds();
    buildMountains();
    buildTreesFar();
    buildGrassForeground();
    buildRoadLines();
    buildSceneForest();
    buildSceneHighway1();
    buildSceneBridge();
    buildSceneMountainRoad();
    buildSceneVillage();
    buildSceneWarehouse();
    buildWorkers();
    return { bundles: buildBundles() };
  }

  // ===========================================================================
  // SCROLL-DRIVEN ANIMATION
  // ===========================================================================
  function initScrollAnimation(refs) {
    if (typeof gsap === 'undefined') {
      console.warn('[BiomeJourney] GSAP not found — animation disabled, static scene shown.');
      return;
    }
    gsap.registerPlugin(ScrollTrigger);

    var stage = $('bjStage');
    var world = $('bjWorld');
    var mountains = $('bjMountains');
    var treesFar = $('bjTreesFar');
    var grass = $('bjGrassFore');
    var clouds = $('bjClouds');
    var wheels = [$('bjWheelFront'), $('bjWheelRear1'), $('bjWheelRear2')];
    var truckBody = $('bjTruckBody');
    var mirror = $('bjMirror');
    var dust = $('bjDust');
    var exhaust = $('bjExhaust');
    var gateDoor = $('bjGateDoor');
    var branding = $('bjBranding');
    var hint = $('bjHint');
    var caption = $('bjCaption');
    var captionEyebrow = $('bjCaptionEyebrow');
    var captionTitle = $('bjCaptionTitle');
    var captionBody = $('bjCaptionBody');
    var forklift = $('bjForklift');
    var lights = [];
    for (var i = 0; i < 8; i++) lights.push($('bjWLight' + i));

    var WORLD_WIDTH = 6200;

    function worldTravel() {
      var stageW = stage.clientWidth;
      return -(WORLD_WIDTH - stageW * 0.62); // keep a margin so warehouse centers nicely
    }

    // Idle suspension bounce (secondary motion only — never drives position)
    var bounceTween = gsap.to(truckBody, {
      y: 2.4, duration: .16, repeat: -1, yoyo: true, ease: 'sine.inOut', paused: true
    });
    var mirrorTween = gsap.to(mirror, {
      rotation: 1.6, duration: .12, repeat: -1, yoyo: true, ease: 'sine.inOut',
      transformOrigin: 'top center', paused: true
    });
    // Forklift idle shuttle inside the warehouse (independent ambient loop)
    gsap.to(forklift, {
      x: '+=36', duration: 1.4, repeat: -1, yoyo: true, ease: 'sine.inOut', paused: false
    });
    // Cloud + sun ambient drift (independent of scroll — pure ambience)
    gsap.to('#bjSun', { y: -6, duration: 4, repeat: -1, yoyo: true, ease: 'sine.inOut' });

    var scenes = [
      { until: 12, eyebrow: 'Chapter 01', title: 'The Bamboo Forest', body: 'Deep in North-East India, mature bamboo is hand-selected and loaded for its journey.' },
      { until: 30, eyebrow: 'Chapter 02', title: 'On the Open Road', body: 'Fully loaded, the truck rolls out along the highway toward its destination.' },
      { until: 48, eyebrow: 'Chapter 03', title: 'Crossing the River', body: 'A steel bridge carries the fleet safely over the water, rain or shine.' },
      { until: 66, eyebrow: 'Chapter 04', title: 'Through the Hills', body: 'Mountain roads test the fleet — and Biome delivers, every time.' },
      { until: 82, eyebrow: 'Chapter 05', title: 'Passing the Village', body: 'Local communities along the route, connected by dependable logistics.' },
      { until: 100, eyebrow: 'Chapter 06', title: 'Warehouse Delivery', body: 'The gate opens. Bamboo is unloaded, inventoried, and ready to ship.' }
    ];
    function updateCaption(progressPct) {
      for (var s = 0; s < scenes.length; s++) {
        if (progressPct <= scenes[s].until) {
          if (captionTitle.textContent !== scenes[s].title) {
            captionEyebrow.textContent = scenes[s].eyebrow;
            captionTitle.textContent = scenes[s].title;
            captionBody.textContent = scenes[s].body;
            gsap.fromTo(caption, { opacity: .0, y: 10 }, { opacity: 1, y: 0, duration: .4, ease: 'power2.out' });
          }
          return;
        }
      }
    }

    var tl = gsap.timeline({
      scrollTrigger: {
        trigger: '#bambooJourney',
        start: 'top top',
        end: '+=' + Math.round(window.innerHeight * 6.4),
        scrub: 1,
        pin: stage,
        anticipatePin: 1,
        onUpdate: function (self) {
          var pct = self.progress * 100;
          updateCaption(pct);

          if (hint) gsap.to(hint, { opacity: pct > 1 ? 0 : 1, duration: .3 });

          // secondary motion only runs while the world is actually translating
          var moving = pct > 13 && pct < 92;
          if (moving) { bounceTween.play(); mirrorTween.play(); }
          else { bounceTween.pause(); mirrorTween.pause(); gsap.set(truckBody, { y: 0 }); gsap.set(mirror, { rotation: 0 }); }
        }
      }
    });

    // ---- Phase A (0 → 13): parked at the forest, crew loads the truck -------
    tl.to({}, { duration: 4 }) // brief establishing hold
      .to(dust, { opacity: .5, duration: 1 })
      .to('#bjWorker0', { opacity: 1, duration: .8 }, '<')
      .to('#bjWorker1', { opacity: 1, duration: .8 }, '-=.5')
      .to('#bjWorker2', { opacity: 1, duration: .8 }, '-=.5')
      .to('#bjBundle0', { x: 0, opacity: 1, duration: 1.4, ease: 'power2.out' }, '-=.3')
      .to(truckBody, { y: 3, duration: .3, ease: 'power1.in' }, '<')
      .to('#bjBundle1', { x: 0, opacity: 1, duration: 1.4, ease: 'power2.out' }, '-=.9')
      .to('#bjBundle2', { x: 0, opacity: 1, duration: 1.4, ease: 'power2.out' }, '-=.9')
      .to('#bjBundle3', { x: 0, opacity: 1, duration: 1.4, ease: 'power2.out' }, '-=.9')
      .to(truckBody, { y: 0, duration: .4, ease: 'bounce.out' }, '-=.4')
      .to(['#bjWorker0', '#bjWorker1', '#bjWorker2'], { opacity: 0, duration: .8 })
      .to(dust, { opacity: 0, duration: .8 }, '<');

    // ---- Phase B (13 → 90): the drive — world + parallax + wheels -----------
    var driveDuration = 60;
    tl.to(exhaust, { opacity: .6, duration: 1 })
      .to(world, { x: worldTravel(), duration: driveDuration, ease: 'none' }, '<')
      .to(mountains, { x: function () { return worldTravel() * 0.28; }, duration: driveDuration, ease: 'none' }, '<')
      .to(treesFar, { x: function () { return worldTravel() * 0.46; }, duration: driveDuration, ease: 'none' }, '<')
      .to(grass, { x: function () { return worldTravel() * 1.35; }, duration: driveDuration, ease: 'none' }, '<')
      .to(clouds, { x: function () { return worldTravel() * 0.12; }, duration: driveDuration, ease: 'none' }, '<')
      .to(wheels, { rotation: '+=2600', duration: driveDuration, ease: 'none', transformOrigin: '50% 50%' }, '<')
      .to(dust, { opacity: .35, duration: driveDuration * .1 }, '<')
      .to(exhaust, { opacity: 0, duration: 2 }, '-=4');

    // ---- Phase C (90 → 97): arrival — gate opens, dust settles --------------
    tl.to(dust, { opacity: 0, duration: 2 })
      .to(gateDoor, { scaleY: 0.06, duration: 3, ease: 'power2.inOut' }, '<')
      .to(lights, { opacity: 1, duration: 2, stagger: .12 }, '-=2');

    // ---- Phase D (97 → 100): unload + branding -------------------------------
    tl.to('#bjWorker0', { opacity: 1, x: '+=2', duration: .8 })
      .to('#bjWorker1', { opacity: 1, duration: .8 }, '-=.5')
      .to(['#bjBundle0', '#bjBundle1', '#bjBundle2', '#bjBundle3'], { opacity: 0, x: '+=30', duration: 1.4, stagger: .15 }, '-=.6')
      .to(['#bjWorker0', '#bjWorker1'], { opacity: 0, duration: .8 }, '+=.2')
      .to(branding, { opacity: 1, duration: 2, onStart: function () { branding.classList.add('is-active'); } }, '-=.3');

    // Rebuild travel targets on resize (keeps truck aligned with the road)
    var resizeTimer;
    window.addEventListener('resize', function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () { ScrollTrigger.refresh(); }, 250);
    });
  }

  // ---- boot -----------------------------------------------------------------
  function boot() {
    var refs = buildAll();
    if (!reduceMotion) {
      initScrollAnimation(refs);
    } else {
      // Reduced motion: show a settled, static warehouse-delivery frame.
      gsap && gsap.set && gsap.set('#bjWorld', { x: -(6200 - window.innerWidth * .62) });
      var hint = $('bjHint'); if (hint) hint.style.display = 'none';
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
