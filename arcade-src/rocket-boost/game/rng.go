package game

// RNG ist ein kleiner xorshift64-PRNG. Er ersetzt math/rand, weil dessen
// Runtime einen erheblichen Teil des WASM-Binaries ausmacht und wir hier nur
// gleichverteilte Float64-Werte brauchen.
type RNG struct {
	state uint64
}

// NewRNG erzeugt einen deterministischen Generator.
func NewRNG(seed int64) *RNG {
	s := uint64(seed)
	if s == 0 {
		s = 0x9e3779b97f4a7c15
	}
	return &RNG{state: s}
}

// Uint64 liefert die nächste Zufallszahl.
func (r *RNG) Uint64() uint64 {
	x := r.state
	x ^= x << 13
	x ^= x >> 7
	x ^= x << 17
	r.state = x
	return x
}

// Float64 liefert einen Wert in [0, 1).
func (r *RNG) Float64() float64 {
	return float64(r.Uint64()>>11) / (1 << 53)
}
