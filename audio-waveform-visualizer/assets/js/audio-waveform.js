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

    const parseNumber = (value, fallback = 0) => {
        if (typeof value === 'number') {
            return Number.isFinite(value) ? value : fallback;
        }

        if (typeof value !== 'string') {
            return fallback;
        }

        const normalized = value.replace(',', '.').trim();
        const parsed = Number.parseFloat(normalized);
        return Number.isFinite(parsed) ? parsed : fallback;
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

    const generateId = (() => {
        let counter = 0;
        return (prefix = 'cmd') => {
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
        const playScenarioBtn = workspace.querySelector('.awv-start-scenario');
        const stopScenarioBtn = workspace.querySelector('.awv-stop-scenario');
        const clearCommandsBtn = workspace.querySelector('.awv-clear-commands');

        const showStatus = (message, type = 'info') => {
            if (!statusLabel) {
                return;
            }

            statusLabel.textContent = message;
            statusLabel.dataset.type = type;
            statusLabel.hidden = !message;
        };

        const updateActionButtons = () => {
            const hasCommands = commands.length > 0;

            if (playScenarioBtn) {
                playScenarioBtn.disabled = !hasCommands || scenarioPlaying;
            }
            if (stopScenarioBtn) {
                stopScenarioBtn.disabled = !scenarioPlaying;
            }
            if (clearCommandsBtn) {
                clearCommandsBtn.disabled = !hasCommands && !scenarioPlaying;
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

            const lines = commands.map((command, index) => {
                const segment = `${formatTime(command.regionStart)}-${formatTime(command.regionEnd)}`;
                const start = command.startOffset.toFixed(2);
                const speed = command.speed.toFixed(2);
                return `${index + 1}. ${command.name} | ${command.trackTitle} | ${segment} | t=${start}s | x${speed}`;
            });

            commandLog.value = lines.join('\n');
        };

        const renderCommandTable = () => {
            if (!commandBody) {
                return;
            }

            commandBody.innerHTML = '';

            if (!commands.length) {
                const emptyRow = document.createElement('tr');
                emptyRow.className = 'awv-command-empty';
                const cell = document.createElement('td');
                cell.colSpan = 6;
                cell.textContent = t('scenarioEmpty', 'No commands yet.');
                emptyRow.appendChild(cell);
                commandBody.appendChild(emptyRow);
                renderCommandLog();
                updateActionButtons();
                return;
            }

            commands.forEach((command) => {
                const row = document.createElement('tr');
                row.dataset.commandId = command.id;

                const nameCell = document.createElement('td');
                nameCell.textContent = command.name;

                const trackCell = document.createElement('td');
                trackCell.textContent = command.trackTitle;

                const segmentCell = document.createElement('td');
                segmentCell.textContent = `${formatTime(command.regionStart)} - ${formatTime(command.regionEnd)}`;

                const speedCell = document.createElement('td');
                speedCell.textContent = command.speed.toFixed(2);

                const startCell = document.createElement('td');
                startCell.textContent = command.startOffset.toFixed(2);

                const actionsCell = document.createElement('td');
                const playButton = document.createElement('button');
                playButton.type = 'button';
                playButton.className = 'button awv-command-play';
                playButton.dataset.commandAction = 'play';
                playButton.dataset.commandId = command.id;
                playButton.textContent = t('commandPlay', 'Play');

                const removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.className = 'button awv-command-remove';
                removeButton.dataset.commandAction = 'remove';
                removeButton.dataset.commandId = command.id;
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
            updateActionButtons();
        };

        const addCommandInternal = (command) => {
            commands.push(command);
            commands.sort((a, b) => a.startOffset - b.startOffset || a.createdAt - b.createdAt);
            renderCommandTable();
        };

        const removeCommandInternal = (id) => {
            const index = commands.findIndex((command) => command.id === id);
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

        const stopScenarioInternal = (notify = true) => {
            scenarioTimeouts.forEach((timeoutId) => window.clearTimeout(timeoutId));
            scenarioTimeouts = [];
            scenarioPlaying = false;
            updateActionButtons();
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
            updateActionButtons();
            showStatus(t('scenarioStarted', 'Scenario started.'), 'info');

            const sorted = [...commands].sort((a, b) => a.startOffset - b.startOffset);

            sorted.forEach((command) => {
                const delay = Math.max(0, command.startOffset * 1000);
                const timeoutId = window.setTimeout(() => {
                    playCommandInternal(command);
                }, delay);
                scenarioTimeouts.push(timeoutId);
            });

            const lastCommand = sorted[sorted.length - 1];
            const scenarioEndDelay = (lastCommand.startOffset + Math.max(0, lastCommand.regionEnd - lastCommand.regionStart) + 1) * 1000;
            const completionTimeout = window.setTimeout(() => {
                scenarioPlaying = false;
                updateActionButtons();
                showStatus(t('scenarioCompleted', 'Scenario completed.'), 'success');
            }, scenarioEndDelay);
            scenarioTimeouts.push(completionTimeout);
        };

        if (playScenarioBtn) {
            playScenarioBtn.addEventListener('click', startScenarioInternal);
        }
        if (stopScenarioBtn) {
            stopScenarioBtn.addEventListener('click', () => stopScenarioInternal(true));
        }
        if (clearCommandsBtn) {
            clearCommandsBtn.addEventListener('click', () => {
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
                const target = event.target;
                if (!(target instanceof HTMLElement)) {
                    return;
                }

                const button = target.closest('button[data-command-action]');
                if (!button) {
                    return;
                }

                const { commandAction, commandId } = button.dataset;
                if (!commandId) {
                    return;
                }

                const command = commands.find((item) => item.id === commandId);
                if (!command) {
                    return;
                }

                if (commandAction === 'play') {
                    playCommandInternal(command);
                } else if (commandAction === 'remove') {
                    removeCommandInternal(commandId);
                }
            });
        }

        const workspaceApi = {
            t,
            showStatus,
            addCommand: (payload) => {
                addCommandInternal(payload);
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
            registerTrack: (track) => {
                trackMap.set(track.id, track);
            },
            unregisterTrack: (trackId) => {
                trackMap.delete(trackId);
            },
        };

        const trackElements = workspace.querySelectorAll('.awv-track');
        trackElements.forEach((trackElement) => {
            const track = initTrack(trackElement, workspaceApi);
            if (track) {
                workspaceApi.registerTrack(track);
            }
        });

        updateActionButtons();
        renderCommandTable();
    };

    const initTrack = (trackElement, workspaceApi) => {
        const waveId = trackElement.dataset.waveId;
        const trackId = trackElement.dataset.track || waveId || `${Date.now()}`;
        const height = parseInt(trackElement.dataset.height || '', 10) || 160;

        const selectButton = trackElement.querySelector('.awv-select');
        const playButton = trackElement.querySelector('.awv-play');
        const stopButton = trackElement.querySelector('.awv-stop');
        const playRegionButton = trackElement.querySelector('.awv-play-region');
        const clearRegionButton = trackElement.querySelector('.awv-clear-region');
        const addCommandButton = trackElement.querySelector('.awv-add-command');

        const waveContainer = trackElement.querySelector('.awv-waveform');
        const progressLabel = trackElement.querySelector('.awv-progress');
        const fileInfo = trackElement.querySelector('.awv-file-info');
        const regionInfo = trackElement.querySelector('.awv-region-info');
        const regionLabel = trackElement.querySelector('.awv-region-label');
        const trackTitle = trackElement.querySelector('.awv-track-title')?.textContent?.trim() || workspaceApi.t('trackLabel', 'Track %d').replace('%d', trackId);

        let wave = null;
        let mediaFrame = null;
        let currentRegion = null;
        let isReady = false;
        let audioTitle = '';

        const setLoading = (loading, message = '') => {
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
            const regionsPlugin = wave.getActivePlugins()?.regions;
            if (!regionsPlugin) {
                return [];
            }
            const regions = regionsPlugin.getRegions();
            if (regions instanceof Map) {
                return Array.from(regions.values());
            }
            if (Array.isArray(regions)) {
                return regions;
            }
            return Object.values(regions || {});
        };

        const updateControls = () => {
            const hasAudio = !!wave;
            const hasRegion = !!currentRegion;
            const isPlaying = Boolean(wave && typeof wave.isPlaying === 'function' && wave.isPlaying());

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

            regionLabel.textContent = `${formatTime(currentRegion.start ?? 0)} - ${formatTime(currentRegion.end ?? 0)}`;
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
            const regions = getRegionsList();
            regions.forEach((region) => region.remove());
            setActiveRegion(null);
            updateControls();
        };

        const destroyWave = () => {
            if (wave) {
                wave.destroy();
                wave = null;
            }

            isReady = false;
            setActiveRegion(null);
            setLoading(false);
            updateControls();

            if (fileInfo) {
                fileInfo.hidden = true;
                fileInfo.textContent = '';
            }
        };

        const attachWaveEvents = () => {
            if (!wave) {
                return;
            }

            wave.on('ready', () => {
                isReady = true;
                setLoading(false);
                updateControls();
            });

            wave.on('loading', (progress) => {
                const message = `${workspaceApi.t('loading', 'Loading...')} ${Math.round(progress)}%`;
                setLoading(true, message);
            });

            wave.on('play', () => {
                updateControls();
            });

            wave.on('pause', () => {
                wave.setPlaybackRate(1);
                updateControls();
            });

            wave.on('finish', () => {
                wave.setPlaybackRate(1);
                updateControls();
            });

            const regionHandler = (region) => {
                if (!region) {
                    return;
                }
                setActiveRegion(region);
                if (typeof region.on === 'function') {
                    region.on('click', () => setActiveRegion(region));
                }
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

            wave.on('error', (error) => {
                console.error(error);
                setLoading(false);
                workspaceApi.showStatus(workspaceApi.t('errorPlayback', 'Unable to play the segment.'), 'error');
            });
        };

        const loadAudio = (url, label) => {
            if (!url || !waveContainer) {
                return;
            }

            destroyWave();

            wave = WaveSurfer.create({
                container: waveContainer,
                height,
                waveColor: '#9dbbf1',
                progressColor: '#3b5cb8',
                cursorColor: '#1f3a93',
                barWidth: 2,
                barGap: 1,
                normalize: true,
                responsive: true,
                plugins: [
                    WaveSurfer.Regions.create({
                        dragSelection: {
                            slop: 5,
                        },
                    }),
                ],
            });

            attachWaveEvents();
            setLoading(true);
            wave.load(url);

            audioTitle = label || url.split('/').pop() || '';
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

                if (!window.wp || !wp.media) {
                    console.error('wp.media is not available. Ensure wp_enqueue_media() is called.');
                    return;
                }

                if (!mediaFrame) {
                    mediaFrame = wp.media({
                        title: workspaceApi.t('selectAudio', 'Select audio'),
                        library: { type: ['audio'] },
                        multiple: false,
                        button: { text: workspaceApi.t('selectAudio', 'Select audio') },
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
                    showTemporaryMessage(progressLabel, workspaceApi.t('noRegion', 'Create a region on the track first.'), 2500);
                    return;
                }
                wave.setPlaybackRate(1);
                wave.play(currentRegion.start, currentRegion.end);
            });
        }

        if (clearRegionButton) {
            clearRegionButton.addEventListener('click', () => {
                if (!wave) {
                    return;
                }
                if (currentRegion) {
                    currentRegion.remove();
                    setActiveRegion(null);
                } else {
                    clearRegions();
                }
            });
        }

        if (addCommandButton) {
            addCommandButton.addEventListener('click', () => {
                if (!currentRegion) {
                    showTemporaryMessage(progressLabel, workspaceApi.t('noRegion', 'Create a region on the track first.'), 2500);
                    return;
                }

                const startPrompt = window.prompt(workspaceApi.t('promptStart', 'Enter start offset (seconds)'), '0');
                if (startPrompt === null) {
                    return;
                }
                const startOffset = Math.max(0, parseNumber(startPrompt, 0));

                const speedPrompt = window.prompt(workspaceApi.t('promptSpeed', 'Enter playback speed (for example 1 or 0.75)'), '1');
                if (speedPrompt === null) {
                    return;
                }
                let speed = parseNumber(speedPrompt, 1);
                if (speed <= 0) {
                    speed = 1;
                }

                const command = workspaceApi.createCommand({
                    trackId,
                    trackTitle,
                    audioTitle,
                    regionStart: currentRegion.start ?? 0,
                    regionEnd: currentRegion.end ?? 0,
                    startOffset,
                    speed,
                });

                if (!command) {
                    workspaceApi.showStatus(workspaceApi.t('commandFailed', 'Failed to add command.'), 'error');
                    return;
                }

                workspaceApi.addCommand(command);
            });
        }

        updateControls();

        return {
            id: trackId,
            label: trackTitle,
            isReady: () => Boolean(wave) && isReady,
            playSegment: (start, end, speed = 1) => {
                if (!wave || !isReady) {
                    return false;
                }
                try {
                    wave.setPlaybackRate(speed > 0 ? speed : 1);
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
        if (!workspaces.length) {
            return;
        }

        workspaces.forEach((workspace) => initWorkspace(workspace));
    });
})();
