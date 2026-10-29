{{--
    Shared Alpine state + methods for a GPS check-in / check-out button
    (My Attendance and the phone Check in page). Included INSIDE an x-data
    object literal. The page must expose the Livewire methods checkIn,
    checkOut and reportLocationFailure (MyAttendance). Never use double
    quotes in here: the result sits inside an x-data="..." attribute.
--}}
phase: 'idle',
busy: false,
remote: @js($remote),
newId() {
    return (window.crypto && typeof crypto.randomUUID === 'function') ? crypto.randomUUID() : null
},
async report(action, code, requestId) {
    this.phase = 'verifying'
    await $wire.reportLocationFailure(action, code, requestId)
},
async attempt(action) {
    if (this.busy) { return }
    this.busy = true
    const method = action === 'check_in' ? 'checkIn' : 'checkOut'
    const requestId = this.newId()

    try {
        if (this.remote) {
            this.phase = 'verifying'
            await $wire[method]({ client_request_id: requestId })
            return
        }

        if (! window.isSecureContext) { await this.report(action, 'insecure_context', requestId); return }
        if (! ('geolocation' in navigator)) { await this.report(action, 'unsupported', requestId); return }

        this.phase = 'locating'
        const queryPosition = (opts) => new Promise((resolve, reject) => {
            navigator.geolocation.getCurrentPosition(resolve, reject, opts)
        })

        let position
        try {
            position = await queryPosition({ enableHighAccuracy: true, timeout: 5000, maximumAge: 0 })
        } catch (geoErr) {
            if (geoErr && geoErr.code !== 1) {
                position = await queryPosition({ enableHighAccuracy: false, timeout: 8000, maximumAge: 60000 })
            } else {
                throw geoErr
            }
        }

        // Freshness retry if browser handed back cached fix
        if (position.timestamp && (Date.now() - position.timestamp) > 60000) {
            try {
                position = await queryPosition({ enableHighAccuracy: true, timeout: 5000, maximumAge: 0 })
            } catch (retryErr) {
                // keep the first reading
            }
        }

        this.phase = 'verifying'
        await $wire[method]({
            latitude: position.coords.latitude,
            longitude: position.coords.longitude,
            accuracy: position.coords.accuracy,
            captured_at: position.timestamp ? Math.round(position.timestamp) : null,
            client_now: Date.now(),
            client_request_id: requestId,
        })
    } catch (error) {
        if (error && typeof error.code === 'number') {
            const code = { 1: 'permission_denied', 2: 'position_unavailable', 3: 'timeout' }[error.code] || 'position_unavailable'
            await this.report(action, code, requestId)
        } else {
            throw error
        }
    } finally {
        this.phase = 'idle'
        this.busy = false
    }
},
