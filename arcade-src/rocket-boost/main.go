//go:build js && wasm

// Rocket Boost — Go/WASM-Spielschicht.
//
// Go besitzt Simulation, Rendering und den Frame-Loop. Die JS-Schale
// (public/arcade/rocket-boost/game.js) kümmert sich ausschließlich um
// Session-Token, Score-API, HTML-Overlays und das Laden des WASM-Moduls.
package main

import (
	"math"
	"syscall/js"

	"sinclear.de/arcade/rocket-boost/game"
)

const fixedStep = 1.0 / 120.0

var (
	document js.Value
	ctx      js.Value
	canvas   js.Value
	shell    js.Value

	world *game.World

	frameFn  js.Func
	resizeFn js.Func
	apiFuncs []js.Func

	cssW, cssH float64
	dpr        float64
	scale      float64
	offX, offY float64

	bgGrad    js.Value
	starsFar  js.Value
	starsNear js.Value
	tileFar   js.Value
	tileNear  js.Value

	scrollFar  float64
	scrollNear float64
	animTime   float64

	paused      bool
	runReported bool
	debugOn     bool

	lastTS float64
	accum  float64

	frameCount int
	debugAccum float64

	particles     [28]particle
	particleCount int
	particleRng   = game.NewRNG(20261101)
	seedCounter   int64
)

type particle struct {
	x, y, vx, vy float64
	life, max    float64
	size         float64
}

func main() {
	document = js.Global().Get("document")
	shell = js.Global().Get("rocketShell")

	canvas = document.Call("getElementById", "game")
	ctx = canvas.Call("getContext", "2d", map[string]any{"alpha": false})

	world = game.New(nextSeed())

	applySize()
	buildStarfield()
	registerAPI()
	registerResize()

	frameFn = js.FuncOf(frame)
	js.Global().Call("requestAnimationFrame", frameFn)

	// Der Go-Scheduler gibt die Kontrolle an den Browser zurück, sobald alle
	// Goroutinen auf JS-Events warten. main darf deshalb nicht zurückkehren.
	select {}
}

func registerAPI() {
	api := js.Global().Get("Object").New()

	start := js.FuncOf(func(this js.Value, args []js.Value) any {
		world.Start(nextSeed())
		paused = false
		runReported = false
		lastTS = 0
		accum = 0
		particleCount = 0
		callShell("setScore", 0)
		callShell("onState", "running")
		return nil
	})

	tap := js.FuncOf(func(this js.Value, args []js.Value) any {
		world.Flap()
		return nil
	})

	pause := js.FuncOf(func(this js.Value, args []js.Value) any {
		next := len(args) > 0 && args[0].Truthy()
		if next != paused {
			paused = next
			lastTS = 0
			accum = 0
		}
		return nil
	})

	setDebug := js.FuncOf(func(this js.Value, args []js.Value) any {
		debugOn = len(args) > 0 && args[0].Truthy()
		return nil
	})

	apiFuncs = append(apiFuncs, start, tap, pause, setDebug)
	api.Set("start", start)
	api.Set("tap", tap)
	api.Set("pause", pause)
	api.Set("setDebug", setDebug)
	js.Global().Set("rocket", api)
}

func registerResize() {
	resizeFn = js.FuncOf(func(this js.Value, args []js.Value) any {
		applySize()
		return nil
	})

	js.Global().Call("addEventListener", "resize", resizeFn)
	js.Global().Call("addEventListener", "orientationchange", resizeFn)

	if vv := js.Global().Get("visualViewport"); vv.Truthy() {
		vv.Call("addEventListener", "resize", resizeFn)
	}
}

func applySize() {
	w := js.Global().Get("innerWidth").Float()
	h := js.Global().Get("innerHeight").Float()
	if w < 1 || h < 1 {
		return
	}

	cssW, cssH = w, h

	d := js.Global().Get("devicePixelRatio").Float()
	if math.IsNaN(d) || math.IsInf(d, 0) || d < 1 {
		d = 1
	}
	// Mehr als 2x bringt optisch kaum etwas, kostet aber viel Füllrate.
	if d > 2 {
		d = 2
	}
	dpr = d

	canvas.Set("width", int(math.Round(cssW*dpr)))
	canvas.Set("height", int(math.Round(cssH*dpr)))

	scale = math.Min(cssW/game.Width, cssH/game.Height)
	offX = (cssW - game.Width*scale) / 2
	offY = (cssH - game.Height*scale) / 2

	// Nach einer Größenänderung ist der Context zurückgesetzt.
	ctx.Set("imageSmoothingEnabled", true)
	ctx.Set("lineJoin", "round")

	bgGrad = ctx.Call("createLinearGradient", 0, 0, 0, cssH)
	bgGrad.Call("addColorStop", 0, "#04060d")
	bgGrad.Call("addColorStop", 0.55, "#081022")
	bgGrad.Call("addColorStop", 1, "#0d1830")
}

func buildStarfield() {
	tileFar = starTile(70, 0.85, 11)
	tileNear = starTile(24, 1.5, 22)
	starsFar = ctx.Call("createPattern", tileFar, "repeat")
	starsNear = ctx.Call("createPattern", tileNear, "repeat")
}

func starTile(count int, maxRadius float64, seed int64) js.Value {
	tile := document.Call("createElement", "canvas")
	tile.Set("width", 256)
	tile.Set("height", 256)

	tctx := tile.Call("getContext", "2d")
	rng := game.NewRNG(seed)

	for i := 0; i < count; i++ {
		x := rng.Float64() * 256
		y := rng.Float64() * 256
		r := 0.35 + rng.Float64()*maxRadius
		alpha := 0.25 + rng.Float64()*0.55

		tctx.Set("globalAlpha", alpha)
		tctx.Set("fillStyle", "#dbeafe")
		tctx.Call("beginPath")
		tctx.Call("arc", x, y, r, 0, math.Pi*2)
		tctx.Call("fill")
	}

	return tile
}

func frame(this js.Value, args []js.Value) any {
	now := 0.0
	if len(args) > 0 {
		now = args[0].Float()
	}

	dt := 0.0
	if lastTS > 0 {
		dt = (now - lastTS) / 1000
	}
	lastTS = now
	if dt < 0 {
		dt = 0
	}
	if dt > 0.1 {
		dt = 0.1
	}

	if dt > 0 {
		animTime += dt
	}

	if !paused && world.Phase == game.Running {
		accum += dt
		steps := 0
		for accum >= fixedStep && steps < 8 {
			scored, died := world.Step(fixedStep)
			accum -= fixedStep
			steps++
			if scored {
				callShell("setScore", world.Score)
			}
			if died {
				onDeath()
				break
			}
		}
		if accum > fixedStep*8 {
			accum = 0
		}

		scrollFar += world.Speed() * dt * 0.22
		scrollNear += world.Speed() * dt * 0.55
	} else if world.Phase == game.Dead {
		world.Step(dt)
	}

	updateParticles(dt)
	draw()
	reportDebug(dt)

	js.Global().Call("requestAnimationFrame", frameFn)
	return nil
}

func onDeath() {
	if runReported {
		return
	}
	runReported = true
	spawnParticles()
	callShell("onState", "dead")
	callShell("onRunEnd", world.Score, world.Elapsed*1000)
}

func spawnParticles() {
	particleCount = 0
	for i := 0; i < len(particles); i++ {
		angle := particleRng.Float64() * math.Pi * 2
		speed := 40 + particleRng.Float64()*190
		p := particle{
			x:    world.X,
			y:    world.Y,
			vx:   math.Cos(angle) * speed,
			vy:   math.Sin(angle) * speed,
			max:  0.45 + particleRng.Float64()*0.5,
			size: 1.5 + particleRng.Float64()*2.8,
		}
		p.life = p.max
		particles[i] = p
	}
	particleCount = len(particles)
}

func updateParticles(dt float64) {
	if particleCount == 0 {
		return
	}
	alive := 0
	for i := 0; i < particleCount; i++ {
		p := particles[i]
		p.life -= dt
		if p.life <= 0 {
			continue
		}
		p.vy += 620 * dt
		p.x += p.vx * dt
		p.y += p.vy * dt
		particles[alive] = p
		alive++
	}
	particleCount = alive
}

func draw() {
	ctx.Call("setTransform", dpr, 0, 0, dpr, 0, 0)
	ctx.Set("globalAlpha", 1)
	ctx.Set("fillStyle", bgGrad)
	ctx.Call("fillRect", 0, 0, cssW, cssH)

	drawStars(starsFar, scrollFar, 0.55, 256)
	drawStars(starsNear, scrollNear, 0.95, 256)

	// Spielfeldrahmen: macht die feste 9:16-Fläche auch auf breiten
	// Bildschirmen als Spielfeld erkennbar.
	frameW := math.Max(1, game.Width*scale-1)
	frameH := math.Max(1, game.Height*scale-1)
	ctx.Set("strokeStyle", "rgba(56,189,248,0.20)")
	ctx.Set("lineWidth", 1)
	ctx.Call("strokeRect", offX+0.5, offY+0.5, frameW, frameH)

	ctx.Call("save")
	ctx.Call("translate", offX, offY)
	ctx.Call("scale", scale, scale)

	for _, wall := range world.Walls {
		drawWall(wall)
	}
	drawRocket()
	drawParticles()

	ctx.Call("restore")
}

func drawStars(pattern js.Value, offset, alpha, tile float64) {
	ox := math.Mod(offset, tile)
	if ox < 0 {
		ox += tile
	}
	oy := math.Mod(offset*0.45, tile)
	if oy < 0 {
		oy += tile
	}

	ctx.Call("save")
	ctx.Set("globalAlpha", alpha)
	ctx.Set("fillStyle", pattern)
	ctx.Call("translate", -ox, -oy)
	ctx.Call("fillRect", 0, 0, cssW+tile, cssH+tile)
	ctx.Call("restore")
}

func drawWall(wall game.Wall) {
	topH := wall.GapY
	bottomY := wall.GapY + wall.GapH
	bottomH := game.Height - bottomY

	// Korpus
	ctx.Set("fillStyle", "#101a30")
	ctx.Call("fillRect", wall.X, 0, game.WallWidth, topH)
	ctx.Call("fillRect", wall.X, bottomY, game.WallWidth, bottomH)

	// Panel-Linie
	ctx.Set("fillStyle", "rgba(148,197,255,0.08)")
	ctx.Call("fillRect", wall.X+11, 0, 2, topH)
	ctx.Call("fillRect", wall.X+11, bottomY, 2, bottomH)

	// Leuchtkante zur Lücke
	ctx.Set("fillStyle", "#38bdf8")
	ctx.Call("fillRect", wall.X, topH-3, game.WallWidth, 3)
	ctx.Call("fillRect", wall.X, bottomY, game.WallWidth, 3)

	// dunkle Fuge direkt hinter der Leuchtkante
	ctx.Set("fillStyle", "#0b1220")
	ctx.Call("fillRect", wall.X, topH-7, game.WallWidth, 4)
	ctx.Call("fillRect", wall.X, bottomY+3, game.WallWidth, 4)
}

func drawRocket() {
	y := world.Y
	tilt := world.Tilt
	if world.Phase == game.Idle {
		y += math.Sin(animTime*2.1) * 7
		tilt = math.Sin(animTime*1.4) * 0.04
	}

	ctx.Call("save")
	ctx.Call("translate", world.X, y)
	ctx.Call("rotate", tilt)

	// Triebwerksflamme
	flame := 9 + math.Sin(animTime*34)*3
	ctx.Set("fillStyle", "#f97316")
	ctx.Call("beginPath")
	ctx.Call("moveTo", -12, -4)
	ctx.Call("lineTo", -12-flame, 0)
	ctx.Call("lineTo", -12, 4)
	ctx.Call("closePath")
	ctx.Call("fill")

	// Rumpf
	ctx.Set("fillStyle", "#e2e8f0")
	ctx.Call("beginPath")
	ctx.Call("moveTo", 14, 0)
	ctx.Call("quadraticCurveTo", 7, -8, -6, -6.5)
	ctx.Call("lineTo", -11, -3)
	ctx.Call("lineTo", -11, 3)
	ctx.Call("lineTo", -6, 6.5)
	ctx.Call("quadraticCurveTo", 7, 8, 14, 0)
	ctx.Call("closePath")
	ctx.Call("fill")

	// Cockpit
	ctx.Set("fillStyle", "#0ea5e9")
	ctx.Call("beginPath")
	ctx.Call("arc", 4.5, 0, 3.2, 0, math.Pi*2)
	ctx.Call("fill")

	// Heckflosse
	ctx.Set("fillStyle", "#f97316")
	ctx.Call("beginPath")
	ctx.Call("moveTo", -9, -2)
	ctx.Call("lineTo", -14, -7)
	ctx.Call("lineTo", -12, 0)
	ctx.Call("closePath")
	ctx.Call("fill")

	ctx.Call("restore")
}

func drawParticles() {
	for i := 0; i < particleCount; i++ {
		p := particles[i]
		alpha := p.life / p.max
		if alpha < 0 {
			alpha = 0
		}
		ctx.Set("globalAlpha", alpha)
		if i%2 == 0 {
			ctx.Set("fillStyle", "#fbbf24")
		} else {
			ctx.Set("fillStyle", "#7dd3fc")
		}
		ctx.Call("fillRect", p.x-p.size/2, p.y-p.size/2, p.size, p.size)
	}
	ctx.Set("globalAlpha", 1)
}

func reportDebug(dt float64) {
	if !debugOn {
		return
	}
	frameCount++
	debugAccum += dt
	if debugAccum < 0.5 {
		return
	}
	fps := float64(frameCount) / debugAccum
	callShell("debug",
		math.Round(fps*10)/10,
		math.Round(dt*1000*10)/10,
		float64(len(world.Walls)),
	)
	frameCount = 0
	debugAccum = 0
}

func nextSeed() int64 {
	seedCounter++
	ms := int64(js.Global().Get("Date").Call("now").Float())
	return ms*1000 + seedCounter
}

func callShell(name string, args ...any) {
	if !shell.Truthy() {
		return
	}
	shell.Call(name, args...)
}
