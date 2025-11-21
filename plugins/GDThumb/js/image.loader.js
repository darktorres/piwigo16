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
        this.queue = this.queue.concat(urls);
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
        while (
            !this.paused &&
            this.queue.length &&
            this.current.length < this.opts.maxRequests
        ) {
            this._processOne(this.queue.shift());
        }
    },

    _processOne: function (url) {
        var img = this.pool.shift() || new Image();
        this.current.push(img);
        var that = this;

        // Vanilla JS: Create handler function for load/error/abort events
        var handleLoadComplete = function (e) {
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
            } else {
                that.errors++;
                that.errorEma++;
                if (that.errorEma >= 20 && that.errorEma < 21) {
                    that.paused = true;
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
        img.src = url;
    },

    _fireChanged: function (type, img) {
        this.opts.onChanged(type, img);
    },
};
