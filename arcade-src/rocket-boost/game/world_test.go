package game

import (
	"math"
	"testing"
)

const step = 1.0 / 120.0

func TestRocketFallsAndDiesAtFloor(t *testing.T) {
	w := New(1)
	w.Start(1)

	for i := 0; i < 1200; i++ {
		w.Step(step)
		if w.Phase == Dead {
			break
		}
	}

	if w.Phase != Dead {
		t.Fatalf("expected rocket to crash into the floor, phase=%v y=%.1f", w.Phase, w.Y)
	}
	if w.DeadFor == 0 && w.Elapsed == 0 {
		t.Fatal("expected elapsed time to advance")
	}
}

func TestFlapReversesVelocity(t *testing.T) {
	w := New(2)
	w.Start(2)

	for i := 0; i < 30; i++ {
		w.Step(step)
	}
	if w.VY <= 0 {
		t.Fatalf("expected downward velocity before flap, got %.1f", w.VY)
	}

	w.Flap()
	if w.VY != flapVelocity {
		t.Fatalf("flap velocity = %.1f, want %.1f", w.VY, flapVelocity)
	}
}

func TestFlapOutsideRunningIsIgnored(t *testing.T) {
	w := New(3)
	w.VY = 123
	w.Flap()
	if w.VY != 123 {
		t.Fatalf("idle flap changed velocity to %.1f", w.VY)
	}
}

func TestScoringHappensOncePerWall(t *testing.T) {
	w := New(4)
	w.Start(4)
	w.Walls = []Wall{{X: w.X - WallWidth - RocketRadius - 1, GapY: 100, GapH: 200}}

	scored, _ := w.Step(step)
	if !scored || w.Score != 1 {
		t.Fatalf("expected first pass to score, scored=%v score=%d", scored, w.Score)
	}

	scored, _ = w.Step(step)
	if scored || w.Score != 1 {
		t.Fatalf("wall scored twice, scored=%v score=%d", scored, w.Score)
	}
}

func TestGapStaysInsidePlayfield(t *testing.T) {
	w := New(5)
	w.Start(5)

	for i := 0; i < 5000; i++ {
		w.Score = i / 40 // künstlich steigern, damit alle Schwierigkeitsstufen auftreten
		w.spawnWall()
	}

	for _, wall := range w.Walls {
		if wall.GapH < minGap || wall.GapH > startGap {
			t.Fatalf("gap height out of range: %.1f", wall.GapH)
		}
		if wall.GapY < gapMarginTop-0.001 {
			t.Fatalf("gap too close to ceiling: %.1f", wall.GapY)
		}
		if wall.GapY+wall.GapH > Height-gapMarginBottom+0.001 {
			t.Fatalf("gap too close to floor: %.1f+%.1f", wall.GapY, wall.GapH)
		}
	}
}

func TestConsecutiveGapsDoNotJumpTooFar(t *testing.T) {
	w := New(6)
	w.Start(6)

	for i := 0; i < 2000; i++ {
		w.spawnWall()
	}

	for i := 1; i < len(w.Walls); i++ {
		prev := w.Walls[i-1].GapY + w.Walls[i-1].GapH/2
		cur := w.Walls[i].GapY + w.Walls[i].GapH/2
		if math.Abs(cur-prev) > maxGapJump+0.001 {
			t.Fatalf("gap centre jumped from %.1f to %.1f", prev, cur)
		}
	}
}

func TestDifficultyIsCapped(t *testing.T) {
	w := New(7)
	w.Score = 9999

	if got := w.Speed(); got != maxSpeed {
		t.Fatalf("speed = %.1f, want %.1f", got, maxSpeed)
	}
	if got := w.GapHeight(); got != minGap {
		t.Fatalf("gap = %.1f, want %.1f", got, minGap)
	}
}

func TestSameSeedProducesSameRun(t *testing.T) {
	a := New(42)
	b := New(42)
	a.Start(42)
	b.Start(42)

	// Identisches, aber nicht konstantes Eingabemuster.
	for i := 0; i < 600; i++ {
		if i%17 == 0 {
			a.Flap()
			b.Flap()
		}
		a.Step(step)
		b.Step(step)
		if a.Phase != b.Phase || a.Score != b.Score {
			t.Fatalf("runs diverged at step %d", i)
		}
	}

	if len(a.Walls) != len(b.Walls) {
		t.Fatalf("wall count differs: %d vs %d", len(a.Walls), len(b.Walls))
	}
	for i := range a.Walls {
		if a.Walls[i] != b.Walls[i] {
			t.Fatalf("wall %d differs: %+v vs %+v", i, a.Walls[i], b.Walls[i])
		}
	}
}

func TestCircleHitsRect(t *testing.T) {
	cases := []struct {
		name string
		cx   float64
		cy   float64
		want bool
	}{
		{"left of rect", 0, 50, false},
		{"overlapping edge", 9, 50, true},
		{"far above", 20, -20, false},
		{"inside", 20, 50, true},
	}
	for _, c := range cases {
		got := circleHitsRect(c.cx, c.cy, RocketRadius, 10, 0, 30, 100)
		if got != c.want {
			t.Errorf("%s: got %v want %v", c.name, got, c.want)
		}
	}
}

func TestSimpleControllerCanScore(t *testing.T) {
	w := New(99)
	w.Start(99)

	for i := 0; i < 120*45; i++ {
		target := Height * 0.5
		for _, wall := range w.Walls {
			if wall.X+WallWidth > w.X-RocketRadius {
				target = wall.GapY + wall.GapH/2
				break
			}
		}
		if w.Y > target && w.VY > 0 {
			w.Flap()
		}
		if _, died := w.Step(step); died {
			t.Fatalf("controller died at score %d (y=%.1f, target=%.1f)", w.Score, w.Y, target)
		}
	}

	t.Logf("controller score after 45s: %d", w.Score)

	if w.Score < 20 {
		t.Fatalf("controller scored only %d in 45s", w.Score)
	}
}
