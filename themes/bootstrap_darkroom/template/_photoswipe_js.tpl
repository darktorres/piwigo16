{combine_css path="themes/bootstrap_darkroom/node_modules/photoswipe/dist/photoswipe.css" order=-11}
{combine_css path="themes/bootstrap_darkroom/node_modules/photoswipe/dist/default-skin/default-skin.css" order=-12}
{combine_script id="photoswipe" path="themes/bootstrap_darkroom/node_modules/photoswipe/dist/photoswipe.js" load="footer"}
{combine_script id="photoswipe.ui" require="photoswipe" path="themes/bootstrap_darkroom/node_modules/photoswipe/dist/photoswipe-ui-default.js" load="footer"}
{footer_script require="photoswipe.ui"}<script>
    var selector = '{$selector}';

    function startPhotoSwipe(idx) {
        document.querySelectorAll(selector).forEach(function(pic) {
            const active = document.getElementById('thumbnail-active');
            if (active) active.classList.add('active');
            var getItems = function() {
                    var items = [];
                    pic.querySelectorAll('a').forEach(function(link) {
                        if (link.getAttribute('data-video')) {
                            var src = link.dataset.srcOriginal,
                                size = link.dataset.sizeOriginal.split('x'),
                                width = size[0],
                                height = size[1],
                                src_preview = link.dataset.srcMedium,
                                size_preview = link.dataset.sizeMedium.split(' x '),
                                width_preview = size_preview[0],
                                height_preview = size_preview[1],
                                href = link.getAttribute('href'),
                                title = '<a href="' + href + '">' + link.dataset.name +
                                '</a><ul><li>' + (link.dataset.description || '') + '</li></ul>';
                            var item = {
                                is_video: true,
                                href: href,
                                src: src_preview,
                                w: width_preview,
                                h: height_preview,
                                title: title,
                                videoProperties: {
                                    src: src,
                                    w: width,
                                    h: height,
                                }
                            };
                        } else {
                            var src_xlarge = link.dataset.srcXlarge,
                                size_xlarge = link.dataset.sizeXlarge.split(' x '),
                                width_xlarge = size_xlarge[0],
                                height_xlarge = size_xlarge[1],
                                src_large = link.dataset.srcLarge,
                                size_large = link.dataset.sizeLarge.split(' x '),
                                width_large = size_large[0],
                                height_large = size_large[1],
                                src_medium = link.dataset.srcMedium,
                                size_medium = link.dataset.sizeMedium.split(' x '),
                                width_medium = size_medium[0],
                                height_medium = size_medium[1],
                                href = link.getAttribute('href'),
                                title = '<a href="' + href + '"><div><div>' + link.dataset.name;
                            title += '</div>';
                            if ((link.dataset.description || '').length > 0) {
                                title +=
                                    '<ul id="pswp--caption--description"><li>' + link.dataset.description + '</li></ul>';
                            }
                            title += '</div></a>';
                            var item = {
                                is_video: false,
                                href: href,
                                mediumImage: {
                                    src: src_medium,
                                    w: width_medium,
                                    h: height_medium,
                                    title: title
                                },
                                largeImage: {
                                    src: src_large,
                                    w: width_large,
                                    h: height_large,
                                    title: title
                                },
                                xlargeImage: {
                                    src: src_xlarge,
                                    w: width_xlarge,
                                    h: height_xlarge,
                                    title: title
                                }
                            };
                        }
                        items.push(item);
                    });
                    return items;
                };
            var items = getItems();

            var pswp = document.querySelector('.pswp');
            var index;
            if (typeof(idx) === "number") {
                index = idx;
            } else {
                const activeLink = pic.querySelector('a.active');
                index = activeLink ? parseInt(activeLink.dataset.index) : 0;
            }
            var history = !navigator.userAgent.match(/IEMobile\/11\.0/);
            var options = {
                index: index,
                showHideOpacity: true,
                closeOnScroll: false,
                closeOnVerticalDrag: false,
                focus: false,
                history: history,
                preload: [1, 2],
                {if $theme_config->social_enabled}
                    shareButtons: [
                        {if $theme_config->social_facebook}
                            { id:'facebook', label:'<i class="fab fa-facebook fa-2x fa-fw"></i> Share on Facebook', url:'https://www.facebook.com/sharer/sharer.php?u={literal}{{url}}{/literal}' },
                        {/if}
                        {if $theme_config->social_twitter}
                            { id:'twitter', label:'<i class="fab fa-twitter fa-2x fa-fw"></i> Tweet', url:'https://twitter.com/intent/tweet?url={literal}{{url}}{/literal}' },
                        {/if}
                        {if $theme_config->social_pinterest}
                            { id:'pinterest', label:'<i class="fab fa-pinterest fa-2x fa-fw"></i> Pin it', url:'http://www.pinterest.com/pin/create/button/?url={literal}{{url}}{/literal}&media=' + window.location + '/../{literal}{{raw_image_url}}{/literal}' },
                        {/if}
                        {if functions::get_device() == 'mobile'}
                            { id:'whatsapp', label:'<i class="fab fa-whatsapp fa-2x fa-fw"></i> Share via WhatsApp', url:'whatsapp://send?text={literal}{{url}}{/literal}', download:true },
                        {/if}
                        { id:'download', label:'<i class="fas fa-cloud-download-alt fa-2x fa-fw"></i> Download image', url:'{literal}{{raw_image_url}}{/literal}', download:true }
                    ],
                {/if}
            };
            var photoSwipe = new PhotoSwipe(pswp, PhotoSwipeUI_Default, items, options);
            var realViewportWidth,
                useLargeImages = false,
                firstResize = true,
                imageSrcWillChange;

            photoSwipe.listen('beforeResize', function() {
                realViewportWidth = photoSwipe.viewportSize.x * window.devicePixelRatio;
                if (useLargeImages && realViewportWidth < 1335) {
                    useLargeImages = false;
                    imageSrcWillChange = true;
                } else if (!useLargeImages && realViewportWidth >= 1335) {
                    useLargeImages = true;
                    imageSrcWillChange = true;
                }

                if (imageSrcWillChange && !firstResize) {
                    photoSwipe.invalidateCurrItems();
                }

                if (firstResize) {
                    firstResize = false;
                }

                imageSrcWillChange = false;
            });

            photoSwipe.listen('gettingData', function(index, item) {
                if (!item.is_video) {
                    if (useLargeImages) {
                        item.src = item.xlargeImage.src;
                        item.w = item.xlargeImage.w;
                        item.h = item.xlargeImage.h;
                        item.title = item.xlargeImage.title;
                    } else {
                        item.src = item.largeImage.src;
                        item.w = item.largeImage.w;
                        item.h = item.largeImage.h;
                        item.title = item.largeImage.title;
                    }
                }
            });

            var autoplayId = null;
            const autoplayBtn = pswp.querySelector('.pswp__button--autoplay');
            if (autoplayBtn) {
                autoplayBtn.addEventListener('click', function(event) {
                    event.preventDefault();
                    if (autoplayId) {
                        clearInterval(autoplayId);
                        autoplayId = null;
                        autoplayBtn.classList.remove('stop');
                    } else {
                        autoplayId = setInterval(function() { photoSwipe.next(); index = photoSwipe.getCurrentIndex(); }, {$theme_config->photoswipe_interval});
                        autoplayBtn.classList.add('stop');
                    }
                });
            }

            photoSwipe.listen('destroy', function() {
                if (autoplayId) {
                    clearInterval(autoplayId);
                    autoplayId = null;
                    if (autoplayBtn) autoplayBtn.classList.remove('stop');
                }
                pic.querySelectorAll('a.active').forEach(a => a.classList.remove('active'));
                if (pswp) pswp.removeEventListener('wheel', wheelHandler);
            });

            photoSwipe.init();

            const wheelHandler = function(e) {
                e.preventDefault();
                if (e.deltaY > 0) {
                    photoSwipe.next();
                } else {
                    photoSwipe.prev();
                }
            };
            if (pswp) pswp.addEventListener('wheel', wheelHandler);

            detectVideo(photoSwipe);

            const attachDetailsHandler = function() {
                const detailsBtn = pswp ? pswp.querySelector('.pswp__button--details') : null;
                if (detailsBtn) {
                    detailsBtn.onclick = function() {
                        location.href = photoSwipe.currItem.href;
                    };
                }
            };

            photoSwipe.listen('initialZoomInEnd', function() {
                var curr_idx = photoSwipe.getCurrentIndex();
                if (curr_idx !== index && autoplayId == null) {
                    photoSwipe.goTo(index);
                }
                attachDetailsHandler();
            });

            photoSwipe.listen('afterChange', function() {
                detectVideo(photoSwipe);
                attachDetailsHandler();
            });

            photoSwipe.listen('beforeChange', function() {
                removeVideo();
            });

            photoSwipe.listen('resize', function() {
                const videoModal = pswp ? pswp.querySelector('.pswp-video-modal') : null;
                if (videoModal) {
                    var vsize = setVideoSize(photoSwipe.currItem, photoSwipe.viewportSize);
                    console.log('PhotoSwipe resize in action. Setting video size to ' + vsize.w + 'x' + vsize.h);
                    videoModal.style.width = vsize.w + 'px';
                    videoModal.style.height = vsize.h + 'px';
                    updateVideoPosition(photoSwipe);
                }
            });

            photoSwipe.listen('close', function() {
                removeVideo();
            });
        });

        function removeVideo() {
            const videoModal = document.querySelector('.pswp-video-modal');
            if (videoModal) {
                const video = document.getElementById('pswp-video');
                if (video) {
                    video.pause();
                    video.src = "";
                    videoModal.remove();
                    const img = document.querySelector('.pswp__img');
                    if (img) img.style.visibility = 'visible';
                    document.removeEventListener('webkitfullscreenchange', null);
                    document.removeEventListener('mozfullscreenchange', null);
                    document.removeEventListener('fullscreenchange', null);
                    if (navigator.userAgent.match(/(iPhone|iPad|Android)/)) {
                        videoModal.style.background = '';
                    }
                } else {
                    videoModal.remove();
                }
            }
        }

        function detectVideo(photoSwipe) {
            var is_video = photoSwipe.currItem.is_video;
            if (is_video) {
                addVideo(photoSwipe.currItem, photoSwipe.viewportSize);
                updateVideoPosition(photoSwipe);
            }
        }

        function addVideo(item, vp) {
            var vfile = item.videoProperties.src;
            var vsize = setVideoSize(item, vp);
            var v = document.createElement('div');
            v.className = 'pswp-video-modal';
            v.style.position = 'absolute';
            v.style.width = vsize.w + 'px';
            v.style.height = vsize.h + 'px';

            v.addEventListener('{if functions::get_device() == 'desktop'}click{else}tap{/if}', function(event) {
                event.preventDefault();
                var playerCode = '<video id="pswp-video" width="100%" height="auto" autoplay controls>' +
                    '<source src="' + vfile + '" type="video/mp4"></source>' +
                    '</video>';
                this.innerHTML = playerCode;
                const img = document.querySelector('.pswp__img');
                if (img) img.style.visibility = 'hidden';
                const video = this.querySelector('video');
                if (video) video.style.visibility = 'visible';
                if (navigator.userAgent.match(/(iPhone|iPad|Android)/)) {
                    this.style.background = 'none';
                }
                const autoBtn = document.querySelector('.pswp__button--autoplay.stop');
                if (autoBtn) autoBtn.click();
            }, { once: true });

            const scrollWrap = document.querySelector('.pswp__scroll-wrap');
            if (scrollWrap) {
                if (navigator.appVersion.indexOf("Windows") !== -1 && navigator.userAgent.match(/(Edge|rv:11)/)) {
                    scrollWrap.parentNode.insertBefore(v, scrollWrap.nextSibling);
                } else {
                    scrollWrap.appendChild(v);
                }
            }

            {* this is soooo nasty, but i have no better idea to fix the fullscreen video issue on OS X, Chrome/Windows *}
            if ((navigator.appVersion.indexOf("Windows") !== -1 && navigator.userAgent.match(/(Chrome|Firefox)/)) || navigator
                .userAgent.match(/(X11|Macintosh)/)) {
                const fullscreenHandler = function(e) {
                    var state = document.fullScreen || document.mozFullScreen || document.webkitIsFullScreen,
                        event = state ? 'FullscreenOn' : 'FullscreenOff',
                        holder_height = item.h;
                    if (event === 'FullscreenOn') {
                        const wrapper = document.getElementById('wrapper');
                        if (wrapper) wrapper.style.display = 'none';
                        document.body.style.height = window.screen.height + 'px';
                        const videoModal = document.querySelector('.pswp-video-modal');
                        if (videoModal) videoModal.style.height = window.screen.height + 'px';
                    } else {
                        const wrapper = document.getElementById('wrapper');
                        if (wrapper) wrapper.style.display = '';
                        document.body.style.height = '';
                        const videoModal = document.querySelector('.pswp-video-modal');
                        if (videoModal) videoModal.style.height = holder_height + 'px';
                    }
                };
                document.addEventListener('webkitfullscreenchange', fullscreenHandler);
                document.addEventListener('mozfullscreenchange', fullscreenHandler);
                document.addEventListener('fullscreenchange', fullscreenHandler);
                document.addEventListener('MSFullscreenChange', fullscreenHandler);
            }
        }

    function updateVideoPosition(o, w, h) {
        var item = o.currItem;
        var vp = o.viewportSize;
        var vsize = setVideoSize(item, vp);
        var top = (vp.y - vsize.h) / 2;
        var left = (vp.x - vsize.w) / 2;
        var videoModal = document.querySelector('.pswp-video-modal');
        if (videoModal) {
            videoModal.style.position = 'absolute';
            videoModal.style.top = top + 'px';
            videoModal.style.left = left + 'px';
        }
    }

    function setVideoSize(item, vp) {
        var w = item.videoProperties.w,
            h = item.videoProperties.h,
            vw = vp.x,
            vh = vp.y,
            r;
        if (vw < w) {
            r = w / h;
            vh = vw / r;
            if (vp.y < vh) {
                vh = vp.y * 0.8;
                vw = vh * r;
            }
            w = vw;
            h = vh;
        } else if (vp.y < (h * 1.2)) {
            r = w / h;
            vh = vp.y * 0.85;
            vw = vh * r;
            w = vw;
            h = vh;
        }

        return {
            w: w,
            h: h
        };
    }
    };

    document.addEventListener('DOMContentLoaded', function() {
        const startBtn = document.getElementById('startPhotoSwipe');
        if (startBtn) {
            startBtn.addEventListener('click', function(event) {
                event.preventDefault();
                startPhotoSwipe();
            });
        }

        {if functions::get_device() != 'desktop'}
            const theImage = document.getElementById('theImage');
            if (theImage && theImage.addEventListener) {
                theImage.addEventListener('doubletap', startPhotoSwipe);
            }
        {/if}

        {if isset($U_SLIDESHOW_START)}
            const slideBtn = document.getElementById('startSlideshow');
            if (slideBtn) {
                slideBtn.addEventListener('click', function() {
                    startPhotoSwipe();
                    const autoBtn = document.querySelector('.pswp__button--autoplay');
                    if (autoBtn) autoBtn.click();
                });
            }
        {/if}

        if (window.location.hash === "#start-slideshow") {
            startPhotoSwipe();
            const autoBtn = document.querySelector('.pswp__button--autoplay');
            if (autoBtn) autoBtn.click();
        }
    });
</script>{/footer_script}