(function () {
    'use strict';

    const strings = (window.AWVSettings && window.AWVSettings.strings) || {};

    const t = (key, fallback) => (Object.prototype.hasOwnProperty.call(strings, key) ? strings[key] : fallback);

    const formatTime = (seconds) => {
        if (typeof seconds !== 'number' || !Number.isFinite(seconds)) {
            return '0:00';
        }

        const total = Math.max(seconds, 0);
        const minutes = Math.floor(total / 60);
        const secs = Math.round(total % 60).toString().padStart(2, '0');

        return `${minutes}:${secs}`;
    };

    const parseNumber = (value, fallback) => {
        if (typeof value === 'number') {
            return Number.isFinite(value) ? value : fallback;
        }

        if (typeof value !== 'string') {
            return fallback;
        }

        const parsed = Number.parseFloat(value.replace(',', '.').trim());
        return Number.isFinite(parsed) ? parsed : fallback;
    };

    const showTemporaryMessage = (element, message, duration) => {
        if (!element) {
            return;
        }
        const timeout = Number.isFinite(duration) ? duration : 2000;
        element.hidden = false;
        element.textContent = message;
        window.setTimeout(() => {
            element.textContent = '';
            element.hidden = true;
        }, timeout);
    };

    const generateId = (() => {
        let counter = 0;
        return (prefix) => {
            counter += 1;
            return `${prefix}-${Date.now()}-${counter}`;
        };
    })();

    const initWorkspace = (workspace) => {
        const commands = [];
        const trackMap = new Map();
        let commandCounter = 0;
        let scenarioTimeouts = [];
        let scenarioPlaying = false;

        const commandBody = workspace.querySelector('.awv-command-body');
        const commandLog = workspace.querySelector('.awv-command-log');
        const statusLabel = workspace.querySelector('.awv-command-status');
        const startScenarioBtn = workspace.querySelector('.awv-start-scenario');
        const stopScenarioBtn = workspace.querySelector('.awv-stop-scenario');
        const clearScenarioBtn = workspace.querySelector('.awv-clear-commands');

        const showStatus = (message, type) => {
            if (!statusLabel) {
                return;
            }
            statusLabel.textContent = message;
            statusLabel.dataset.type = type;
            statusLabel.hidden = !message;
        };

        const updateScenarioButtons = () => {
            const hasCommands = commands.length > 0;
            if (startScenarioBtn) {
                startScenarioBtn.disabled = !hasCommands || scenarioPlaying;
            }
            if (stopScenarioBtn) {
                stopScenarioBtn.disabled = !scenarioPlaying;
            }
            if (clearScenarioBtn) {
                clearScenarioBtn.disabled = !hasCommands && !scenarioPlaying;
            }
        };

        const renderCommandLog = () => {
            if (!commandLog) {
                return;
            }
            if (!commands.length) {
                commandLog.value = t('commandTextPlaceholder', 'Add commands to populate the script.');
                return;
            }
            const rows = [];
            for (let i = 0; i < commands.length; i += 1) {
                const cmd = commands[i];
                rows.push(
                    `${i + 1}. ${cmd.name} | ${cmd.trackTitle} | ${formatTime(cmd.regionStart)}-${formatTime(cmd.regionEnd)} | ` +
                        `t=${cmd.startOffset.toFixed(2)}s | x${cmd.speed.toFixed(2)}`,
                );
            }
            commandLog.value = rows.join('\n');
        };

        const renderCommandTable = () => {
            if (!commandBody) {
                return;
            }
            commandBody.innerHTML = '';

            if (!commands.length) {
                const row = document.createElement('tr');
                row.className = 'awv-command-empty';
                const cell = document.createElement('td');
                cell.colSpan = 6;
                cell.textContent = t('scenarioEmpty', 'No commands yet.');
                row.appendChild(cell);
                commandBody.appendChild(row);
                renderCommandLog();
                updateScenarioButtons();
                return;
            }

            commands.forEach((cmd) => {
                const row = document.createElement('tr');
                row.dataset.commandId = cmd.id;

                const nameCell = document.createElement('td');
                nameCell.textContent = cmd.name;

                const trackCell = document.createElement('td');
                trackCell.textContent = cmd.trackTitle;

                const segmentCell = document.createElement('td');
                segmentCell.textContent = `${formatTime(cmd.regionStart)} - ${formatTime(cmd.regionEnd)}`;

                const speedCell = document.createElement('td');
                speedCell.textContent = cmd.speed.toFixed(2);

                const startCell = document.createElement('td');
                startCell.textContent = cmd.startOffset.toFixed(2);

                const actionsCell = document.createElement('td');
                const playButton = document.createElement('button');
                playButton.type = 'button';
                playButton.className = 'button awv-command-play';
                playButton.dataset.commandAction = 'play';
                playButton.dataset.commandId = cmd.id;
                playButton.textContent = t('commandPlay', 'Play');

                const removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.className = 'button awv-command-remove';
                removeButton.dataset.commandAction = 'remove';
                removeButton.dataset.commandId = cmd.id;
                removeButton.textContent = t('commandRemove', 'Remove');

                actionsCell.appendChild(playButton);
                actionsCell.appendChild(removeButton);

                row.appendChild(nameCell);
                row.appendChild(trackCell);
                row.appendChild(segmentCell);
                row.appendChild(speedCell);
                row.appendChild(startCell);
                row.appendChild(actionsCell);

                commandBody.appendChild(row);
            });

            renderCommandLog();
            updateScenarioButtons();
        };

        const addCommandInternal = (command) => {
            commands.push(command);
            commands.sort((a, b) => {
                if (a.startOffset !== b.startOffset) {
                    return a.startOffset - b.startOffset;
                }
                return a.createdAt - b.createdAt;
            });
            renderCommandTable();
        };

        const removeCommandInternal = (commandId) => {
            const index = commands.findIndex((item) => item.id === commandId);
            if (index === -1) {
                return;
            }
            commands.splice(index, 1);
            renderCommandTable();
            showStatus(t('commandRemoved', 'Command removed.'), 'info');
        };

        const playCommandInternal = (command) => {
            const track = trackMap.get(command.trackId);
            if (!track || !track.isReady()) {
                showStatus(t('noAudioLoaded', 'Load audio on this track first.'), 'warning');
                return false;
            }
            const success = track.playSegment(command.regionStart, command.regionEnd, command.speed);
            if (!success) {
                showStatus(t('errorPlayback', 'Unable to play the segment.'), 'error');
            }
            return success;
        };

        const stopScenarioInternal = (notify) => {
            scenarioTimeouts.forEach((timeoutId) => window.clearTimeout(timeoutId));
            scenarioTimeouts = [];
            scenarioPlaying = false;
            updateScenarioButtons();
            if (notify) {
                showStatus(t('scenarioStopped', 'Scenario stopped.'), 'info');
            }
        };

        const startScenarioInternal = () => {
            if (!commands.length) {
                showStatus(t('scenarioEmpty', 'No commands yet.'), 'warning');
                return;
            }

            stopScenarioInternal(false);
            scenarioPlaying = true;
            updateScenarioButtons();
            showStatus(t('scenarioStarted', 'Scenario started.'), 'info');

            const sorted = commands.slice().sort((a, b) => a.startOffset - b.startOffset);
            sorted.forEach((command) => {
                const delay = Math.max(0, command.startOffset * 1000);
                const timeoutId = window.setTimeout(() => {
                    playCommandInternal(command);
                }, delay);
                scenarioTimeouts.push(timeoutId);
            });

            const lastCommand = sorted[sorted.length - 1];
            const segmentDuration = Math.max(0, lastCommand.regionEnd - lastCommand.regionStart);
            const scenarioDuration = (lastCommand.startOffset + segmentDuration + 1) * 1000;
            const completionTimeout = window.setTimeout(() => {
                scenarioPlaying = false;
                updateScenarioButtons();
                showStatus(t('scenarioCompleted', 'Scenario completed.'), 'success');
            }, scenarioDuration);
            scenarioTimeouts.push(completionTimeout);
        };

        if (startScenarioBtn) {
            startScenarioBtn.addEventListener('click', startScenarioInternal);
        }

        if (stopScenarioBtn) {
            stopScenarioBtn.addEventListener('click', () => stopScenarioInternal(true));
        }

        if (clearScenarioBtn) {
            clearScenarioBtn.addEventListener('click', () => {
                if (!commands.length && !scenarioPlaying) {
                    return;
                }
                stopScenarioInternal(false);
                commands.length = 0;
                renderCommandTable();
                showStatus(t('scenarioStopped', 'Scenario stopped.'), 'info');
            });
        }

        if (commandBody) {
            commandBody.addEventListener('click', (event) => {
                const button = event.target.closest('button[data-command-action]');
                if (!button) {
                    return;
                }
                const commandId = button.dataset.commandId;
                const command = commands.find((item) => item.id === commandId);
                if (!command) {
                    return;
                }
                if (button.dataset.commandAction === 'play') {
                    playCommandInternal(command);
                }
                if (button.dataset.commandAction === 'remove') {
                    removeCommandInternal(commandId);
                }
            });
        }

        const workspaceApi = {
            t,
            showStatus,
            addCommand: (command) => {
                addCommandInternal(command);
                showStatus(t('commandAdded', 'Command added.'), 'success');
            },
            createCommand: (data) => {
                commandCounter += 1;
                return {
                    id: generateId('cmd'),
                    name: `${t('commandTableTitle', 'Command')} ${commandCounter}`,
                    createdAt: Date.now(),
                    ...data,
                };
            },
            registerTrack: (track) => trackMap.set(track.id, track),
            unregisterTrack: (trackId) => trackMap.delete(trackId),
        };

        const trackElements = workspace.querySelectorAll('.awv-track');
        for (let i = 0; i < trackElements.length; i += 1) {
            const track = initTrack(trackElements[i], workspaceApi);
            if (track) {
                workspaceApi.registerTrack(track);
            }
        }

        updateScenarioButtons();
        renderCommandTable();
    };

    // ????????? ????? ???????
    const initTrack = (trackElement, workspaceApi) => {
        const waveId = trackElement.getAttribute('data-wave-id');
        const trackAttr = trackElement.getAttribute('data-track');
        const trackId = trackAttr || waveId || String(Date.now());
        const heightAttr = trackElement.getAttribute('data-height') || '';
        const height = Number.parseInt(heightAttr, 10) || 160;

        const selectButton = trackElement.querySelector('.awv-select');
        const uploadButton = trackElement.querySelector('.awv-upload');
        const fileInput = trackElement.querySelector('.awv-file-input');
        const playButton = trackElement.querySelector('.awv-play');
        const stopButton = trackElement.querySelector('.awv-stop');
        const playRegionButton = trackElement.querySelector('.awv-play-region');
        const clearRegionButton = trackElement.querySelector('.awv-clear-region');
        const addCommandButton = trackElement.querySelector('.awv-add-command');
        const zoomSlider = trackElement.querySelector('.awv-zoom');
        const speedInput = trackElement.querySelector('.awv-speed');
        const resetSpeedButton = trackElement.querySelector('.awv-reset-speed');

        const waveContainer = trackElement.querySelector('.awv-waveform');
        const progressLabel = trackElement.querySelector('.awv-progress');
        const fileInfo = trackElement.querySelector('.awv-file-info');
        const regionInfo = trackElement.querySelector('.awv-region-info');
        const regionLabel = trackElement.querySelector('.awv-region-label');

        let trackTitle = workspaceApi.t('trackLabel', 'Track %d').replace('%d', trackId);
        const trackTitleElement = trackElement.querySelector('.awv-track-title');
        if (trackTitleElement && typeof trackTitleElement.textContent === 'string') {
            const text = trackTitleElement.textContent.replace(/\s+/g, ' ').trim();
            if (text) {
                trackTitle = text;
            }
        }

        let wave = null;
        let mediaFrame = null;
        let currentRegion = null;
        let isReady = false;
        let audioTitle = '';
        let objectUrl = null;
        let trackSpeed = speedInput ? parseNumber(speedInput.value, 1) : 1;
        if (!Number.isFinite(trackSpeed) || trackSpeed <= 0) {
            trackSpeed = 1;
        }
        let trackZoom = zoomSlider ? Number.parseInt(zoomSlider.value, 10) : 150;
        if (!Number.isFinite(trackZoom) || trackZoom <= 0) {
            trackZoom = 150;
        }

        const setLoading = (loading, message) => {
            if (!progressLabel) {
                return;
            }
            if (loading) {
                progressLabel.hidden = false;
                progressLabel.textContent = message || workspaceApi.t('loading', 'Loading...');
            } else {
                progressLabel.hidden = true;
                progressLabel.textContent = '';
            }
        };

        const getRegionsList = () => {
            if (!wave) {
                return [];
            }
            if (wave.regions && wave.regions.list) {
                return Object.keys(wave.regions.list).map((key) => wave.regions.list[key]);
            }
            const plugins = wave.getActivePlugins && wave.getActivePlugins();
            if (plugins && plugins.regions && typeof plugins.regions.getRegions === 'function') {
                const raw = plugins.regions.getRegions();
                if (Array.isArray(raw)) {
                    return raw;
                }
                if (raw instanceof Map) {
                    return Array.from(raw.values());
                }
                if (raw && typeof raw === 'object') {
                    return Object.keys(raw).map((key) => raw[key]);
                }
            }
            return [];
        };

        const updateControls = () => {
            const hasAudio = Boolean(wave);
            const hasRegion = Boolean(currentRegion);
            const isPlaying = wave && typeof wave.isPlaying === 'function' && wave.isPlaying();

            if (playButton) {
                playButton.disabled = !isReady;
                playButton.textContent = workspaceApi.t(isPlaying ? 'pause' : 'play', isPlaying ? 'Pause' : 'Play');
            }
            if (stopButton) {
                stopButton.disabled = !hasAudio;
            }
            if (playRegionButton) {
                playRegionButton.disabled = !hasRegion;
            }
            if (clearRegionButton) {
                clearRegionButton.disabled = getRegionsList().length === 0;
            }
            if (addCommandButton) {
                addCommandButton.disabled = !hasRegion;
            }
        };

        const updateRegionInfo = () => {
            if (!regionInfo || !regionLabel) {
                return;
            }
            if (!currentRegion) {
                regionInfo.hidden = true;
                regionLabel.textContent = '';
                return;
            }
            regionLabel.textContent = `${formatTime(currentRegion.start || 0)} - ${formatTime(currentRegion.end || 0)}`;
            regionInfo.hidden = false;
        };

        const setActiveRegion = (region) => {
            if (currentRegion && currentRegion.element) {
                currentRegion.element.classList.remove('awv-region-active');
            }
            currentRegion = region || null;
            if (currentRegion && currentRegion.element) {
                currentRegion.element.classList.add('awv-region-active');
            }
            updateRegionInfo();
            updateControls();
        };

        const clearRegions = () => {
            if (!wave) {
                return;
            }
            if (typeof wave.clearRegions === 'function') {
                wave.clearRegions();
            } else if (wave.regions && typeof wave.regions.clear === 'function') {
                wave.regions.clear();
            } else {
                const list = getRegionsList();
                for (let i = 0; i < list.length; i += 1) {
                    if (list[i] && typeof list[i].remove === 'function') {
                        list[i].remove();
                    }
                }
            }
            setActiveRegion(null);
            updateControls();
        };

        const destroyWave = () => {
            if (wave && typeof wave.destroy === 'function') {
                wave.destroy();
            }
            wave = null;
            isReady = false;
            setActiveRegion(null);
            setLoading(false);
            updateControls();

            if (fileInfo) {
                fileInfo.hidden = true;
                fileInfo.textContent = '';
            }
            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
                objectUrl = null;
            }
        };

        const applyZoom = () => {
            if (wave && typeof wave.zoom === 'function') {
                wave.zoom(trackZoom);
            }
        };

        const attachWaveEvents = (regionsFactoryUsed) => {
            if (!wave) {
                return;
            }

            wave.on('ready', () => {
                isReady = true;
                setLoading(false);
                wave.setPlaybackRate(trackSpeed > 0 ? trackSpeed : 1);
                applyZoom();
                updateControls();
            });

            wave.on('loading', (progress) => {
                const message = `${workspaceApi.t('loading', 'Loading...')} ${Math.round(progress)}%`;
                setLoading(true, message);
            });

            wave.on('play', updateControls);
            wave.on('pause', () => {
                wave.setPlaybackRate(trackSpeed > 0 ? trackSpeed : 1);
                updateControls();
            });
            wave.on('finish', () => {
                wave.setPlaybackRate(trackSpeed > 0 ? trackSpeed : 1);
                updateControls();
            });

            const regionHandler = (region) => {
                if (!region) {
                    return;
                }
                setActiveRegion(region);
            };

            wave.on('region-created', regionHandler);
            wave.on('region-updated', regionHandler);
            wave.on('region-update-end', regionHandler);
            wave.on('region-removed', (region) => {
                if (region && currentRegion && region.id === currentRegion.id) {
                    setActiveRegion(null);
                }
                updateControls();
            });

            if (regionsFactoryUsed && wave.regions && typeof wave.regions.enableDragSelection === 'function') {
                wave.regions.enableDragSelection({ color: 'rgba(59, 92, 184, 0.3)' });
            }

            wave.on('error', (error) => {
                console.error(error);
                setLoading(false);
                workspaceApi.showStatus(workspaceApi.t('errorPlayback', 'Unable to play the segment.'), 'error');
            });
        };

        const createWave = () => {
            if (!waveContainer || typeof window.WaveSurfer === 'undefined') {
                workspaceApi.showStatus('WaveSurfer is not available.', 'error');
                return false;
            }

            const RegionsFactory = (window.WaveSurfer && window.WaveSurfer.regions)
                ? window.WaveSurfer.regions
                : window.WaveSurferRegions;

            const plugins = [];
            if (RegionsFactory && typeof RegionsFactory.create === 'function') {
                plugins.push(RegionsFactory.create({ dragSelection: true }));
            }

            wave = window.WaveSurfer.create({
                container: waveContainer,
                height,
                waveColor: '#9dbbf1',
                progressColor: '#3b5cb8',
                cursorColor: '#1f3a93',
                barWidth: 2,
                barGap: 1,
                normalize: true,
                responsive: true,
                plugins,
            });

            attachWaveEvents(plugins.length > 0);
            window.setTimeout(applyZoom, 0);
            return Boolean(wave);
        };

        const loadAudio = (source) => {
            if (!waveContainer) {
                return;
            }

            destroyWave();
            if (!createWave()) {
                return;
            }

            setLoading(true);

            if (source.file) {
                if (typeof wave.loadBlob === 'function') {
                    wave.loadBlob(source.file);
                } else {
                    objectUrl = URL.createObjectURL(source.file);
                    wave.load(objectUrl);
                }
            } else if (source.url) {
                wave.load(source.url);
            } else {
                setLoading(false);
                workspaceApi.showStatus('No audio source provided.', 'error');
                return;
            }

            audioTitle = source.label || (source.file && source.file.name) || (source.url ? source.url.split('/').pop() : '') || '';
            if (fileInfo) {
                fileInfo.hidden = false;
                fileInfo.textContent = audioTitle;
            }
            if (selectButton) {
                selectButton.textContent = workspaceApi.t('changeAudio', 'Replace audio');
            }
        };

        if (selectButton) {
            selectButton.addEventListener('click', (event) => {
                event.preventDefault();

                if (window.wp && window.wp.media) {
                    if (!mediaFrame) {
                        mediaFrame = window.wp.media({
                            title: workspaceApi.t('selectAudio', 'Select audio'),
                            library: { type: ['audio'] },
                            multiple: false,
                            button: { text: workspaceApi.t('selectAudio', 'Select audio') },
                        });

                        mediaFrame.on('select', () => {
                            const selection = mediaFrame.state().get('selection');
                            if (!selection) {
                                return;
                            }
                            const attachment = selection.first();
                            if (!attachment || typeof attachment.toJSON !== 'function') {
                                return;
                            }
                            const data = attachment.toJSON();
                            loadAudio({
                                url: data && data.url,
                                label: data && (data.filename || data.title),
                            });
                        });
                    }

                    mediaFrame.open();
                } else if (fileInput) {
                    fileInput.click();
                }
            });
        }

        if (uploadButton && fileInput) {
            uploadButton.addEventListener('click', () => fileInput.click());
        }

        if (fileInput) {
            fileInput.addEventListener('change', () => {
                if (!fileInput.files || !fileInput.files.length) {
                    return;
                }
                const file = fileInput.files[0];
                if (!file) {
                    return;
                }
                loadAudio({ file, label: file.name });
                fileInput.value = '';
            });
        }

        if (playButton) {
            playButton.addEventListener('click', () => {
                if (!wave || !isReady) {
                    return;
                }
                wave.setPlaybackRate(trackSpeed > 0 ? trackSpeed : 1);
                wave.playPause();
                updateControls();
            });
        }

        if (stopButton) {
            stopButton.addEventListener('click', () => {
                if (!wave) {
                    return;
                }
                wave.stop();
                updateControls();
            });
        }

        if (playRegionButton) {
            playRegionButton.addEventListener('click', () => {
                if (!wave || !currentRegion) {
                    showTemporaryMessage(progressLabel, workspaceApi.t('noRegion', 'Create a region on the track first.'), 2000);
                    return;
                }
                wave.setPlaybackRate(trackSpeed > 0 ? trackSpeed : 1);
                if (typeof currentRegion.play === 'function') {
                    currentRegion.play();
                } else {
                    wave.play(currentRegion.start, currentRegion.end);
                }
            });
        }

        if (clearRegionButton) {
            clearRegionButton.addEventListener('click', () => {
                if (!wave) {
                    return;
                }
                if (currentRegion && typeof currentRegion.remove === 'function') {
                    currentRegion.remove();
                } else {
                    clearRegions();
                }
                setActiveRegion(null);
            });
        }

        if (addCommandButton) {
            addCommandButton.addEventListener('click', () => {
                if (!currentRegion) {
                    showTemporaryMessage(progressLabel, workspaceApi.t('noRegion', 'Create a region on the track first.'), 2000);
                    return;
                }

                const startPrompt = window.prompt(workspaceApi.t('promptStart', 'Enter start offset (seconds)'), '0');
                if (startPrompt === null) {
                    return;
                }
                const startOffset = Math.max(0, parseNumber(startPrompt, 0));

                const speedPrompt = window.prompt(workspaceApi.t('promptSpeed', 'Enter playback speed (for example 1 or 0.75)'), trackSpeed.toString());
                if (speedPrompt === null) {
                    return;
                }
                let customSpeed = parseNumber(speedPrompt, trackSpeed);
                if (!Number.isFinite(customSpeed) || customSpeed <= 0) {
                    customSpeed = trackSpeed;
                }

                const command = workspaceApi.createCommand({
                    trackId,
                    trackTitle,
                    audioTitle,
                    regionStart: currentRegion.start || 0,
                    regionEnd: currentRegion.end || 0,
                    startOffset,
                    speed: customSpeed,
                });

                if (!command) {
                    workspaceApi.showStatus(workspaceApi.t('commandFailed', 'Failed to add command.'), 'error');
                    return;
                }

                workspaceApi.addCommand(command);
            });
        }

        if (speedInput) {
            speedInput.addEventListener('change', () => {
                const parsed = parseNumber(speedInput.value, trackSpeed);
                trackSpeed = parsed > 0 ? parsed : 1;
                speedInput.value = trackSpeed.toFixed(2);
                if (wave) {
                    wave.setPlaybackRate(trackSpeed);
                }
            });
        }

        if (resetSpeedButton) {
            resetSpeedButton.addEventListener('click', () => {
                trackSpeed = 1;
                if (speedInput) {
                    speedInput.value = '1';
                }
                if (wave) {
                    wave.setPlaybackRate(1);
                }
            });
        }

        if (zoomSlider) {
            zoomSlider.addEventListener('input', () => {
                const parsed = Number.parseInt(zoomSlider.value, 10);
                if (Number.isFinite(parsed) && parsed > 0) {
                    trackZoom = parsed;
                    applyZoom();
                }
            });
        }

        updateControls();
        setLoading(false);
        setActiveRegion(null);

        return {
            id: trackId,
            label: trackTitle,
            isReady: () => Boolean(wave) && isReady,
            playSegment: (start, end, speed) => {
                if (!wave || !isReady) {
                    return false;
                }
                const playbackRate = speed && speed > 0 ? speed : trackSpeed;
                try {
                    wave.setPlaybackRate(playbackRate > 0 ? playbackRate : 1);
                    wave.play(start, end);
                    return true;
                } catch (error) {
                    console.error(error);
                    return false;
                }
            },
        };
    };

    document.addEventListener('DOMContentLoaded', () => {
        const workspaces = document.querySelectorAll('.awv-workspace');
        for (let i = 0; i < workspaces.length; i += 1) {
            initWorkspace(workspaces[i]);
        }
    });
})();
