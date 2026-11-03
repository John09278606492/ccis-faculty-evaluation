// Bounded process recovery shared by the campus and desktop host.
class RestartPolicy {
    constructor({ maximum = 5, stableMs = 60000, baseMs = 1000, ceilingMs = 30000 } = {}) {
        Object.assign(this, { maximum, stableMs, baseMs, ceilingMs });
        this.failures = 0;
        this.startedAt = 0;
    }
    started(now = Date.now()) { this.startedAt = now; }
    failed(now = Date.now()) {
        if (now - this.startedAt >= this.stableMs) this.failures = 0;
        this.failures++;
        return this.failures > this.maximum
            ? { exhausted: true, failures: this.failures, delayMs: null }
            : { exhausted: false, failures: this.failures, delayMs: Math.min(this.ceilingMs, this.baseMs * 2 ** (this.failures - 1)) };
    }
}
module.exports = { RestartPolicy };
