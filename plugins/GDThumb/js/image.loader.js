function ImageLoader(opts) {
    // Vanilla JS: Object.assign instead of jQuery.extend
    this.opts = Object.assign(
        {
            maxRequests: 30,
            onChanged: function() {}, // noop function
        },
        opts || {},
    );
}

ImageLoader.prototype = {
    loaded: 0,
    errors: 0,
    errorEma: 0,

    paused: false,

    current: [],
    queue: [],
    pool: [],

    remaining: function () {
        return this.current.length + this.queue.length;
    },

    add: function (urls) {
        console.log("[ImageLoader] add() called with " + urls.length + " URLs");
        this.queue = this.queue.concat(urls);
        console.log("[ImageLoader] Queue size now: " + this.queue.length);
        this._fireChanged("add");
        this._checkQueue();
    },

    clear: function () {
        this.queue.length = 0;
        // Vanilla JS: Remove all event listeners from images
        while (this.current.length) {
            var img = this.current.pop();
            img.onload = null;
            img.onerror = null;
            img.onabort = null;
        }
        this.loaded = this.errors = this.errorEma = 0;
    },

    pause: function (val) {
        if (val !== undefined) {
            this.paused = val;
            this._checkQueue();
        }
        return this.paused;
    },

    _checkQueue: function () {
        var started = 0;
        while (
            !this.paused &&
            this.queue.length &&
            this.current.length < this.opts.maxRequests
        ) {
            this._processOne(this.queue.shift());
            started++;
        }
        if (started > 0) {
            console.log("[ImageLoader] Started " + started + " image loads, current active: " + this.current.length);
        }
    },

    _processOne: function (url) {
        var img = this.pool.shift() || new Image();
        this.current.push(img);
        var that = this;

        // Vanilla JS: Create handler function for load/error/abort events
        var handleLoadComplete = function (e) {
            console.log("[ImageLoader] Image " + e.type + ": " + img.src);

            // Remove event listeners
            img.onload = null;
            img.onerror = null;
            img.onabort = null;

            // Remove from current array
            var index = that.current.indexOf(img);
            if (index > -1) {
                that.current.splice(index, 1);
            }

            if (e.type === "load") {
                that.loaded++;
                that.errorEma *= 0.9;
                console.log("[ImageLoader] Stats: loaded=" + that.loaded + ", errors=" + that.errors + ", remaining=" + that.remaining());
            } else {
                that.errors++;
                that.errorEma++;
                console.log("[ImageLoader] Error count: " + that.errors + ", errorEma: " + that.errorEma.toFixed(2));
                if (that.errorEma >= 20 && that.errorEma < 21) {
                    that.paused = true;
                    console.log("[ImageLoader] Too many errors, auto-pausing");
                }
            }
            that._fireChanged(e.type, img);
            that._checkQueue();
            that.pool.push(img);
        };

        // Vanilla JS: Attach event handlers
        img.onload = handleLoadComplete;
        img.onerror = handleLoadComplete;
        img.onabort = handleLoadComplete;
        console.log("[ImageLoader] Setting img.src = " + url);
        img.src = url;
    },

    _fireChanged: function (type, img) {
        this.opts.onChanged(type, img);
    },
};
