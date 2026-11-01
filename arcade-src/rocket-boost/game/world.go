// Package game enthält die reine Spiellogik von Rocket Boost.
//
// Das Paket importiert bewusst kein syscall/js und läuft damit auch nativ,
// sodass Physik, Kollision und Schwierigkeitskurve mit "go test" prüfbar sind.
package game

import (
	"math"
)

// Logische Welt. Das Spielfeld hat immer dieses Seitenverhältnis (9:16) und
// wird im Renderer auf die tatsächliche Viewport-Größe skaliert.
const (
	Width  = 360.0
	Height = 640.0
)

// Spielparameter. Bewusst als Konstanten, damit die Balance an einer Stelle
// justiert werden kann.
const (
	RocketX      = 100.0
	RocketRadius = 9.0

	gravity      = 1500.0
	flapVelocity = -430.0
	maxFallSpeed = 820.0

	WallWidth   = 58.0
	wallSpacing = 248.0

	startSpeed = 168.0
	speedStep  = 7.0
	maxSpeed   = 268.0

	startGap = 196.0
	gapStep  = 5.0
	minGap   = 134.0

	gapMarginTop    = 62.0
	gapMarginBottom = 62.0
	maxGapJump      = 170.0

	maxTilt = 0.55
	minTilt = -0.42
)

// Phase beschreibt den Zustand einer Partie.
type Phase int

const (
	// Idle: Startbildschirm, die Rakete schwebt nur.
	Idle Phase = iota
	// Running: Physik und Kollision laufen.
	Running
	// Dead: Partie beendet, die Rakete bleibt stehen.
	Dead
)

// Wall ist ein Korridorelement der Raumstation. GapY/GapH beschreiben die
// Lücke zwischen oberer und unterer Wand.
type Wall struct {
	X      float64
	GapY   float64
	GapH   float64
	Scored bool
}

// World hält den kompletten Simulationszustand einer Partie.
type World struct {
	Phase   Phase
	X       float64
	Y       float64
	VY      float64
	Tilt    float64
	Walls   []Wall
	Score   int
	Elapsed float64
	DeadFor float64

	rng        *RNG
	spawnAccum float64
}

// New erzeugt eine Welt im Idle-Zustand. Der Seed macht Läufe reproduzierbar.
func New(seed int64) *World {
	w := &World{}
	w.Reset(seed)
	return w
}

// Reset setzt die Welt zurück, ohne die Kapazität der Wandliste aufzugeben.
func (w *World) Reset(seed int64) {
	w.rng = NewRNG(seed)
	w.Phase = Idle
	w.X = RocketX
	w.Y = Height * 0.42
	w.VY = 0
	w.Tilt = 0
	w.Walls = w.Walls[:0]
	w.Score = 0
	w.Elapsed = 0
	w.DeadFor = 0
	w.spawnAccum = 0
}

// Start beginnt eine neue Partie.
func (w *World) Start(seed int64) {
	w.Reset(seed)
	w.Phase = Running
	w.spawnWall()
}

// Flap gibt einen Schubimpuls. Die Geschwindigkeit wird gesetzt (nicht
// addiert), damit die Steuerung unabhängig von der Framerate identisch wirkt.
func (w *World) Flap() {
	if w.Phase != Running {
		return
	}
	w.VY = flapVelocity
}

// Speed liefert die aktuelle Scrollgeschwindigkeit in px/s.
func (w *World) Speed() float64 {
	speed := startSpeed + float64(w.Score/5)*speedStep
	if speed > maxSpeed {
		speed = maxSpeed
	}
	return speed
}

// GapHeight liefert die aktuelle lichte Weite der Korridore.
func (w *World) GapHeight() float64 {
	gap := startGap - float64(w.Score/5)*gapStep
	if gap < minGap {
		gap = minGap
	}
	return gap
}

// Step führt einen Simulationsschritt aus. Die Rückgabewerte melden, ob in
// diesem Schritt ein Punkt erzielt wurde und ob die Rakete abgestürzt ist.
func (w *World) Step(dt float64) (scored bool, died bool) {
	if w.Phase == Dead {
		w.DeadFor += dt
		return false, false
	}
	if w.Phase != Running {
		return false, false
	}

	// Gegen sehr große Zeitsprünge (Tab-Wechsel, Debugger) absichern.
	if dt <= 0 {
		return false, false
	}
	if dt > 0.05 {
		dt = 0.05
	}

	w.Elapsed += dt

	w.VY += gravity * dt
	if w.VY > maxFallSpeed {
		w.VY = maxFallSpeed
	}
	w.Y += w.VY * dt

	speed := w.Speed()

	w.spawnAccum += speed * dt
	for w.spawnAccum >= wallSpacing {
		w.spawnAccum -= wallSpacing
		w.spawnWall()
	}

	for i := range w.Walls {
		w.Walls[i].X -= speed * dt
	}

	// Wände einsammeln, Punkte vergeben, ausgelaufene entfernen.
	kept := w.Walls[:0]
	for _, wall := range w.Walls {
		if wall.X+WallWidth < -24 {
			continue
		}
		if !wall.Scored && wall.X+WallWidth < w.X-RocketRadius {
			wall.Scored = true
			w.Score++
			scored = true
		}
		kept = append(kept, wall)
	}
	w.Walls = kept

	// Neigung weich nachziehen, damit die Rakete nicht ruckartig kippt.
	target := w.VY / 780
	if target > maxTilt {
		target = maxTilt
	}
	if target < minTilt {
		target = minTilt
	}
	w.Tilt += (target - w.Tilt) * math.Min(1, dt*9)

	if w.Y-RocketRadius < 0 || w.Y+RocketRadius > Height {
		w.die()
		return scored, true
	}

	for _, wall := range w.Walls {
		if circleHitsRect(w.X, w.Y, RocketRadius, wall.X, 0, WallWidth, wall.GapY) ||
			circleHitsRect(w.X, w.Y, RocketRadius, wall.X, wall.GapY+wall.GapH, WallWidth, Height-(wall.GapY+wall.GapH)) {
			w.die()
			return scored, true
		}
	}

	return scored, false
}

func (w *World) die() {
	w.Phase = Dead
}

// spawnWall hängt den nächsten Korridor rechts außerhalb des Bildes an.
func (w *World) spawnWall() {
	gapH := w.GapHeight()
	maxY := Height - gapMarginBottom - gapH
	if maxY < gapMarginTop {
		maxY = gapMarginTop
	}

	y := gapMarginTop + w.rng.Float64()*(maxY-gapMarginTop)

	// Aufeinanderfolgende Lücken dürfen nicht beliebig weit springen, sonst
	// entstehen bei hoher Geschwindigkeit unlösbare Situationen.
	if n := len(w.Walls); n > 0 {
		prevCenter := w.Walls[n-1].GapY + w.Walls[n-1].GapH/2
		lo := prevCenter - maxGapJump - gapH/2
		hi := prevCenter + maxGapJump - gapH/2
		if lo < gapMarginTop {
			lo = gapMarginTop
		}
		if hi > maxY {
			hi = maxY
		}
		if lo <= hi {
			y = lo + w.rng.Float64()*(hi-lo)
		}
	}

	w.Walls = append(w.Walls, Wall{
		X:    Width + 8,
		GapY: y,
		GapH: gapH,
	})
}

// circleHitsRect prüft Kreis-gegen-Rechteck. Die Rakete bekommt damit eine
// deutlich verzeihende Hitbox als ein Rechteck.
func circleHitsRect(cx, cy, r, rx, ry, rw, rh float64) bool {
	nearestX := math.Max(rx, math.Min(cx, rx+rw))
	nearestY := math.Max(ry, math.Min(cy, ry+rh))
	dx := cx - nearestX
	dy := cy - nearestY
	return dx*dx+dy*dy < r*r
}
