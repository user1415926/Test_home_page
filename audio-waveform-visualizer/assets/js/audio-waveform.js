(function () {
    const settings = window.AWVSettings || {};
    const strings = settings.strings || {};

    const t = (key, fallback) => strings[key] || fallback;

    const formatTime = (seconds) => {
        if (!Number.isFinite(seconds)) {
            return '0:00';
        }

        const totalSeconds = Math.max(Number(seconds) || 0, 0);
        const minutes = Math.floor(totalSeconds / 60);
        const secs = Math.round(totalSeconds % 60)
            .toString()
            .padStart(2, '0');

        return `${minutes}:${secs}`;
    };

    const showTemporaryMessage = (element, message, duration = 2000) => {
        if (!element) {
            return;
        }

        element.hidden = false;
        element.textContent = message;
        window.setTimeout(() => {
            element.textContent = '';
            element.hidden = true;
        }, duration);
    };

    const initPlayer = (wrapper) => {
        if (!wrapper) {
            return;
        }

        const waveformContainer = wrapper.querySelector('.awv-waveform');
        const progressLabel = wrapper.querySelector('.awv-progress');
        const regionInfo = wrapper.querySelector('.awv-region-info');
        const regionLabel = wrapper.querySelector('.awv-region-label');
        const fileInfo = wrapper.querySelector('.awv-file-info');

        const selectButton = wrapper.querySelector('.awv-select');
        const playButton = wrapper.querySelector('.awv-play');
        const stopButton = wrapper.querySelector('.awv-stop');
        const playRegionButton = wrapper.querySelector('.awv-play-region');
        const clearRegionButton = wrapper.querySelector('.awv-clear-region');

        const targetHeight = parseInt(wrapper.getAttribute('data-height'), 10) || 160;

        let wave = null;
        let mediaFrame = null;
        let currentRegion = null;
        let isReady = false;

        const setLoading = (isLoading, message = '') => {
            if (!progressLabel) {
                return;
            }

            if (isLoading) {
                progressLabel.hidden = false;
                progressLabel.textContent = message || t('loading', '?????????');
            } else {
                progressLabel.textContent = '';
                progressLabel.hidden = true;
            }
        };

        const disablePlaybackControls = () => {
            if (playButton) {
                playButton.disabled = true;
                playButton.textContent = t('play', '?????????????');
            }
            if (stopButton) {
                stopButton.disabled = true;
            }
        };

        const resetRegion = () => {
            if (wave) {
                const regionsPlugin = wave.getActivePlugins()?.regions;
                if (regionsPlugin) {
                    const regions = regionsPlugin.getRegions();
                    regions.forEach((region) => region.remove());
                }
            }

            currentRegion = null;

            if (playRegionButton) {
                playRegionButton.disabled = true;
            }
            if (clearRegionButton) {
                clearRegionButton.disabled = !wave;
            }
            if (regionInfo) {
                regionInfo.hidden = true;
            }
        };

        const updateRegionInfo = () => {
            if (!regionInfo || !regionLabel) {
                return;
            }

            if (!currentRegion) {
                regionInfo.hidden = true;
                return;
            }

            const start = formatTime(currentRegion.start ?? 0);
            const end = formatTime(currentRegion.end ?? 0);
            regionLabel.textContent = `${start} ? ${end}`;
            regionInfo.hidden = false;
        };

        const destroyWave = () => {
            if (!wave) {
                return;
            }

            resetRegion();
            wave.destroy();
            wave = null;
            currentRegion = null;
            isReady = false;

            disablePlaybackControls();

            if (clearRegionButton) {
                clearRegionButton.disabled = true;
            }
            if (fileInfo) {
                fileInfo.hidden = true;
                fileInfo.textContent = '';
            }
        };

        const handleRegionCreated = (region) => {
            if (!wave) {
                return;
            }

            if (currentRegion && currentRegion.id !== region.id) {
                currentRegion.remove();
            }

            currentRegion = region;

            if (playRegionButton) {
                playRegionButton.disabled = false;
            }

            updateRegionInfo();
        };

        const attachWaveSurferListeners = () => {
            if (!wave) {
                return;
            }

            wave.on('ready', () => {
                isReady = true;
                setLoading(false);
                if (playButton) {
                    playButton.disabled = false;
                }
                if (stopButton) {
                    stopButton.disabled = false;
                }
                if (clearRegionButton) {
                    clearRegionButton.disabled = false;
                }
            });

            wave.on('play', () => {
                if (playButton) {
                    playButton.textContent = t('pause', '?????');
                }
            });

            wave.on('pause', () => {
                if (playButton) {
                    playButton.textContent = t('play', '?????????????');
                }
            });

            wave.on('finish', () => {
                if (playButton) {
                    playButton.textContent = t('play', '?????????????');
                }
            });

            wave.on('loading', (progress) => {
                const message = `${t('loading', '?????????')} ${Math.round(progress)}%`;
                setLoading(true, message);
            });

            wave.on('region-created', handleRegionCreated);
            wave.on('region-updated', handleRegionCreated);
            wave.on('region-update-end', handleRegionCreated);

            wave.on('region-removed', (region) => {
                if (currentRegion && region.id === currentRegion.id) {
                    currentRegion = null;
                    if (playRegionButton) {
                        playRegionButton.disabled = true;
                    }
                    updateRegionInfo();
                }
            });

            wave.on('error', (error) => {
                const message = error?.message || t('error', '?? ??????? ????????? ?????.');
                setLoading(false);
                showTemporaryMessage(progressLabel, message, 4000);
            });
        };

        const loadAudio = (url, label) => {
            if (!url) {
                return;
            }

            destroyWave();

            wave = WaveSurfer.create({
                container: waveformContainer,
                height: targetHeight,
                waveColor: '#9dbbf1',
                progressColor: '#3b5cb8',
                cursorColor: '#1f3a93',
                barWidth: 2,
                barGap: 1,
                normalize: true,
                responsive: true,
                plugins: [
                    WaveSurfer.Regions.create({
                        dragSelection: true,
                    }),
                ],
            });

            attachWaveSurferListeners();
            resetRegion();
            disablePlaybackControls();
            setLoading(true);

            wave.load(url);

            if (fileInfo) {
                fileInfo.hidden = false;
                fileInfo.textContent = label || url.split('/').pop();
            }

            if (selectButton) {
                selectButton.textContent = t('changeAudio', '???????? ?????');
            }
        };

        if (selectButton) {
            selectButton.addEventListener('click', (event) => {
                event.preventDefault();

                if (!window.wp || !wp.media) {
                    // wp_enqueue_media() should provide this, but guard just in case.
                    // eslint-disable-next-line no-console
                    console.error('wp.media ??????????. ?????????, ??? wp_enqueue_media() ??????????.');
                    return;
                }

                if (!mediaFrame) {
                    mediaFrame = wp.media({
                        title: t('selectAudio', '??????? ?????'),
                        library: { type: ['audio'] },
                        multiple: false,
                        button: { text: t('selectAudio', '??????? ?????') },
                    });

                    mediaFrame.on('select', () => {
                        const attachment = mediaFrame.state().get('selection').first();
                        if (!attachment) {
                            return;
                        }

                        const data = attachment.toJSON();
                        loadAudio(data.url, data.filename || data.title);
                    });
                }

                mediaFrame.open();
            });
        }

        if (playButton) {
            playButton.addEventListener('click', () => {
                if (!wave || !isReady) {
                    return;
                }

                wave.playPause();
            });
        }

        if (stopButton) {
            stopButton.addEventListener('click', () => {
                if (!wave) {
                    return;
                }

                wave.stop();
                if (playButton) {
                    playButton.textContent = t('play', '?????????????');
                }
            });
        }

        if (playRegionButton) {
            playRegionButton.addEventListener('click', () => {
                if (!wave || !currentRegion) {
                    showTemporaryMessage(progressLabel, t('noRegion', '??????? ???????? ??????? ?? ?????.'), 2500);
                    return;
                }

                currentRegion.play();
            });
        }

        if (clearRegionButton) {
            clearRegionButton.addEventListener('click', () => {
                if (!wave) {
                    return;
                }

                resetRegion();
            });
        }

        // Initial UI state.
        disablePlaybackControls();
        resetRegion();
        setLoading(false);
        if (fileInfo) {
            fileInfo.hidden = true;
        }
    };

    document.addEventListener('DOMContentLoaded', () => {
        const players = document.querySelectorAll('.awv-player');
        if (!players.length) {
            return;
        }

        players.forEach((player) => initPlayer(player));
    });
})();
