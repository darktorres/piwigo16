function ImageLoader(opts) {
    this.opts = Object.assign(
        {
            maxRequests: 30,
            onChanged: function() {},
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
        while (this.current.length) {
            var img = this.current.pop();
            if (img._loaderHandler) {
                img.removeEventListener('load',  img._loaderHandler);
                img.removeEventListener('error', img._loaderHandler);
                img.removeEventListener('abort', img._loaderHandler);
                img._loaderHandler = null;
            }
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

        function handler(e) {
            img.removeEventListener('load',  handler);
            img.removeEventListener('error', handler);
            img.removeEventListener('abort', handler);
            img._loaderHandler = null;
            img.onload = null;

            var idx = that.current.indexOf(img);
            if (idx !== -1) that.current.splice(idx, 1);

            if (e.type === "load") {
                that.loaded++;
                that.errorEma *= 0.9;
            } else {
                that.errors++;
                that.errorEma++;
                if (that.errorEma >= 20 && that.errorEma < 21)
                    that.paused = true;
            }
            that._fireChanged(e.type, img);
            that._checkQueue();
            that.pool.push(img);
        }

        img._loaderHandler = handler;
        img.addEventListener('load',  handler);
        img.addEventListener('error', handler);
        img.addEventListener('abort', handler);
        img.src = url;
    },

    _fireChanged: function (type, img) {
        this.opts.onChanged(type, img);
    },
};
