(function (window, document) {
	'use strict';

	const DEFAULT_LOOKAHEAD = 0.1; // seconds
	const SCHEDULER_INTERVAL = 25; // milliseconds
	const MAX_BARS = 8;

	class EventBus {
		constructor() {
			this.listeners = {};
		}

		on(event, callback) {
			if (!this.listeners[event]) {
				this.listeners[event] = [];
			}
			this.listeners[event].push(callback);
			return () => this.off(event, callback);
		}

		off(event, callback) {
			if (!this.listeners[event]) {
				return;
			}
			this.listeners[event] = this.listeners[event].filter((cb) => cb !== callback);
		}

		emit(event, payload) {
			if (!this.listeners[event]) {
				return;
			}
			this.listeners[event].forEach((cb) => cb(payload));
		}
	}

	class MPCAudioEngine {
		constructor(bus) {
			this.bus = bus;
			this.audioContext = null;
			this.masterGain = null;
			this.outputNodes = new Map();
			this.metronomeGain = null;
			this.metronomeBuffer = null;
			this.isRecordingSample = false;
			this.mediaRecorder = null;
			this.recordedChunks = [];
		}

		async ensureContext() {
			if (this.audioContext) {
				if (this.audioContext.state === 'suspended') {
					await this.audioContext.resume();
				}
				return this.audioContext;
			}

			this.audioContext = new (window.AudioContext || window.webkitAudioContext)({ sampleRate: 44100 });
			this.masterGain = this.audioContext.createGain();
			this.masterGain.gain.value = 0.85;
			this.masterGain.connect(this.audioContext.destination);
			this.metronomeGain = this.audioContext.createGain();
			this.metronomeGain.gain.value = 0.0;
			this.metronomeGain.connect(this.masterGain);
			await this.buildMetronomeBuffer();
			return this.audioContext;
		}

		async buildMetronomeBuffer() {
			const ctx = await this.ensureContext();
			const duration = 0.05;
			const buffer = ctx.createBuffer(1, ctx.sampleRate * duration, ctx.sampleRate);
			const data = buffer.getChannelData(0);
			for (let i = 0; i < data.length; i++) {
				data[i] = Math.sin((Math.PI * 2 * i) / data.length) * Math.pow(1 - i / data.length, 3);
			}
			this.metronomeBuffer = buffer;
		}

		setMasterVolume(value) {
			if (!this.masterGain) {
				return;
			}
			this.masterGain.gain.value = value;
		}

		setMetronomeLevel(value) {
			if (!this.metronomeGain) {
				return;
			}
			this.metronomeGain.gain.value = value;
		}

		async loadSampleFromFile(file) {
			await this.ensureContext();
			const arrayBuffer = await file.arrayBuffer();
			const audioBuffer = await this.audioContext.decodeAudioData(arrayBuffer);
			const dataUrl = await this.fileToDataUrl(file);
			return {
				name: file.name,
				src: dataUrl,
				duration: audioBuffer.duration,
				buffer: audioBuffer,
				start: 0,
				end: audioBuffer.duration,
				gain: 0.8,
				pan: 0,
				tune: 0,
				rootNote: 60,
				slices: [],
			};
		}

		async fileToDataUrl(file) {
			return new Promise((resolve, reject) => {
				const reader = new FileReader();
				reader.onload = () => resolve(reader.result);
				reader.onerror = reject;
				reader.readAsDataURL(file);
			});
		}

		async recordSample(seconds = 10) {
			if (this.isRecordingSample) {
				throw new Error('Recorder already active');
			}

			if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
				throw new Error('Microphone access is unavailable');
			}

			await this.ensureContext();

			this.isRecordingSample = true;
			this.recordedChunks = [];
			const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
			this.mediaRecorder = new MediaRecorder(stream, { mimeType: 'audio/webm' });

			return new Promise((resolve, reject) => {
				const timeout = setTimeout(() => {
					if (this.mediaRecorder && this.mediaRecorder.state === 'recording') {
						this.mediaRecorder.stop();
					}
				}, seconds * 1000);

				this.mediaRecorder.ondataavailable = (event) => {
					if (event.data.size > 0) {
						this.recordedChunks.push(event.data);
					}
				};

				this.mediaRecorder.onerror = (error) => {
					stream.getTracks().forEach((track) => track.stop());
					this.isRecordingSample = false;
					clearTimeout(timeout);
					reject(error);
				};

				this.mediaRecorder.onstop = async () => {
					stream.getTracks().forEach((track) => track.stop());
					const blob = new Blob(this.recordedChunks, { type: 'audio/webm' });
					try {
						const file = new File([blob], 'recording.webm', { type: 'audio/webm' });
						const sample = await this.loadSampleFromFile(file);
						this.isRecordingSample = false;
						resolve(sample);
					} catch (err) {
						this.isRecordingSample = false;
						reject(err);
					}
				};

				this.mediaRecorder.start();
			});
		}

		stopRecordingSample() {
			if (this.mediaRecorder && this.mediaRecorder.state === 'recording') {
				this.mediaRecorder.stop();
			}
		}

		buildPadOutput(padId) {
			if (!this.audioContext) {
				return null;
			}
			if (this.outputNodes.has(padId)) {
				return this.outputNodes.get(padId);
			}

			const gainNode = this.audioContext.createGain();
			const panNode = this.audioContext.createStereoPanner();
			gainNode.connect(panNode);
			panNode.connect(this.masterGain);
			this.outputNodes.set(padId, { gainNode, panNode });
			return this.outputNodes.get(padId);
		}

		playPad(pad, options = {}) {
			if (!pad || !pad.sample || !pad.sample.buffer) {
				return;
			}

			const velocity = typeof options.velocity === 'number' ? options.velocity : 1;
			const startOffset = pad.sample.start || 0;
			const endPoint = pad.sample.end || pad.sample.buffer.duration;
			const duration = Math.max(0.01, endPoint - startOffset);
			const ctx = this.audioContext;
			const source = ctx.createBufferSource();
			source.buffer = pad.sample.buffer;
			source.playbackRate.value = Math.pow(2, pad.sample.tune / 12);

			const output = this.buildPadOutput(pad.id);
			if (!output) {
				return;
			}

			output.gainNode.gain.setValueAtTime(0.0001, ctx.currentTime);
			output.gainNode.gain.exponentialRampToValueAtTime(Math.max(0.0001, pad.sample.gain * velocity), ctx.currentTime + (pad.adsr.attack || 0.001));
			const releaseTime = ctx.currentTime + duration - (pad.adsr.release || 0.2);
			output.gainNode.gain.setValueAtTime(Math.max(0.0001, pad.sample.gain * velocity), releaseTime);
			output.gainNode.gain.exponentialRampToValueAtTime(0.0001, releaseTime + (pad.adsr.release || 0.2));

			output.panNode.pan.value = pad.pan || 0;

			source.connect(output.gainNode);
			source.start(ctx.currentTime, startOffset, duration + 2);
		}

		playMetronome(time, accent = false) {
			if (!this.metronomeBuffer || !this.audioContext) {
				return;
			}
			const source = this.audioContext.createBufferSource();
			source.buffer = this.metronomeBuffer;
			const gainNode = this.audioContext.createGain();
			gainNode.gain.value = accent ? 0.9 : 0.4;
			source.connect(gainNode);
			gainNode.connect(this.metronomeGain);
			source.start(time);
		}
	}

	class MPCSequencer {
		constructor(engine, bus) {
			this.engine = engine;
			this.bus = bus;
			this.isPlaying = false;
			this.isRecording = false;
			this.sequence = null;
			this.tempo = 96;
			this.swing = 50;
			this.quantize = 16;
			this.lookAhead = DEFAULT_LOOKAHEAD;
			this.nextNoteTime = 0;
			this.currentStep = 0;
			this.schedulerId = null;
			this.startTime = 0;
			this.metronomeEnabled = false;
		}

		loadSequence(sequence) {
			this.sequence = sequence;
			this.currentStep = 0;
			this.nextNoteTime = 0;
		}

		setTempo(bpm) {
			this.tempo = Math.max(40, Math.min(240, bpm));
		}

		setSwing(amount) {
			this.swing = Math.max(50, Math.min(75, amount));
		}

		setQuantize(resolution) {
			this.quantize = parseInt(resolution, 10) || 16;
		}

		enableMetronome(enabled) {
			this.metronomeEnabled = enabled;
			this.engine.setMetronomeLevel(enabled ? 0.4 : 0.0);
		}

		start() {
			if (!this.sequence || this.isPlaying) {
				return;
			}
			this.engine.ensureContext();
			this.isPlaying = true;
			this.startTime = this.engine.audioContext.currentTime + 0.05;
			this.nextNoteTime = this.startTime;
			this.currentStep = 0;
			this.schedulerId = setInterval(() => this.scheduler(), SCHEDULER_INTERVAL);
			this.bus.emit('transport:start', { step: this.currentStep });
		}

		stop() {
			if (!this.isPlaying) {
				return;
			}
			this.isPlaying = false;
			clearInterval(this.schedulerId);
			this.schedulerId = null;
			this.bus.emit('transport:stop', { step: this.currentStep });
		}

		toggleRecord(enable) {
			if (typeof enable === 'boolean') {
				this.isRecording = enable;
			} else {
				this.isRecording = !this.isRecording;
			}
			this.bus.emit('transport:record', { recording: this.isRecording });
		}

		getStepDuration() {
			return 60 / this.tempo / 4; // 16th note
		}

		getTotalSteps() {
			if (!this.sequence) {
				return 0;
			}
			return (this.sequence.bars || 1) * 16;
		}

		scheduler() {
			if (!this.isPlaying || !this.sequence) {
				return;
			}

			const ctx = this.engine.audioContext;
			while (this.nextNoteTime < ctx.currentTime + this.lookAhead) {
				this.scheduleStepEvents(this.currentStep, this.nextNoteTime);
				const stepDuration = this.getStepDuration();
				this.nextNoteTime += this.applySwing(stepDuration, this.currentStep);
				this.currentStep = (this.currentStep + 1) % this.getTotalSteps();
			}
		}

		applySwing(stepDuration, stepIndex) {
			if (this.swing === 50) {
				return stepDuration;
			}
			const swingRatio = (this.swing - 50) / 50; // -? to +?
			if (stepIndex % 2 === 1) {
				return stepDuration * (1 + swingRatio * 0.5);
			}
			return stepDuration * (1 - swingRatio * 0.5);
		}

		scheduleStepEvents(stepIndex, time) {
			const totalSteps = this.getTotalSteps();
			const measureStep = stepIndex % 16;
			const barIndex = Math.floor(stepIndex / 16);
			const accent = measureStep === 0;
			if (this.metronomeEnabled) {
				this.engine.playMetronome(time, accent);
			}

			const grid = this.sequence.grid || [];
			const step = grid[stepIndex];
			if (step && Array.isArray(step.events)) {
				step.events.forEach((event) => {
					this.bus.emit('sequence:event', {
						stepIndex,
						event,
						time,
					});
				});
			}

			this.bus.emit('sequence:step', {
				stepIndex,
				barIndex,
				time,
				accent,
				measureStep,
				totalSteps,
			});
		}

		capturePadEvent(padId, velocity = 1) {
			if (!this.isPlaying || !this.isRecording || !this.sequence) {
				return;
			}

			const ctx = this.engine.audioContext;
			const elapsed = ctx.currentTime - this.startTime;
			const stepDuration = this.getStepDuration();
			let targetStep = Math.round(elapsed / stepDuration);
			targetStep = Math.max(0, Math.min(this.getTotalSteps() - 1, targetStep));

			const step = this.sequence.grid[targetStep];
			if (!step) {
				return;
			}

			const trackId = this.sequence.activeTrack || 'TRK-01';
			const existing = step.events.find((evt) => evt.trackId === trackId && evt.padId === padId);
			if (!existing) {
				step.events.push({
					trackId,
					padId,
					velocity,
					length: 1,
				});
			}

			this.bus.emit('sequence:updated', {
				type: 'record',
				stepIndex: targetStep,
				padId,
			});
		}
	}

	class MPCProjectStore {
		constructor(initialProject) {
			this.project = JSON.parse(JSON.stringify(initialProject));
			this.attachRuntime();
		}

		attachRuntime() {
			// runtime-only fields
			this.project.runtime = {
				activeBank: 0,
				activeSequenceIndex: 0,
				metronome: false,
				recordReady: false,
				modified: false,
			};
		}

		get state() {
			return this.project;
		}

		markModified() {
			this.project.runtime.modified = true;
		}

		setActiveBank(index) {
			this.project.runtime.activeBank = index;
			return this.getActiveBank();
		}

		getActiveBank() {
			const banks = this.project.state ? this.project.state.banks : this.project.banks;
			if (banks && banks.length) {
				return banks[this.project.runtime.activeBank] || banks[0];
			}
			return null;
		}

		getActiveSequence() {
			const sequences = this.project.state ? this.project.state.sequences : this.project.sequences;
			if (sequences && sequences.length) {
				return sequences[this.project.runtime.activeSequenceIndex] || sequences[0];
			}
			return null;
		}

		selectSequence(index) {
			const sequences = this.project.state ? this.project.state.sequences : this.project.sequences;
			if (!sequences || !sequences.length) {
				return;
			}
			this.project.runtime.activeSequenceIndex = Math.max(0, Math.min(sequences.length - 1, index));
			this.project.state.global.tempo = sequences[this.project.runtime.activeSequenceIndex].tempo;
		}

		addSequence(template) {
			const sequences = this.project.state.sequences;
			if (sequences.length >= 20) {
				return;
			}
			const newSequence = Object.assign(
				{
					id: `SEQ-${sequences.length + 1}`,
					name: `Sequence ${sequences.length + 1}`,
					bars: 4,
					tempo: this.project.state.global.tempo,
					swing: this.project.state.global.swing,
					tracks: template ? JSON.parse(JSON.stringify(template.tracks || [])) : [],
					grid: template ? JSON.parse(JSON.stringify(template.grid || [])) : this.buildEmptyGrid(4, 16),
					mutes: [],
				},
				template || {}
			);
			sequences.push(newSequence);
			this.selectSequence(sequences.length - 1);
			this.markModified();
		}

		buildEmptyGrid(bars, stepsPerBar) {
			const totalSteps = bars * stepsPerBar;
			const grid = [];
			for (let i = 0; i < totalSteps; i++) {
				grid.push({ index: i, events: [], accent: 0, swing: null });
			}
			return grid;
		}

		setPadSample(bankIndex, padIndex, sample) {
			const bank = this.project.state.banks[bankIndex];
			if (!bank) {
				return;
			}
			const pad = bank.pads[padIndex];
			if (!pad) {
				return;
			}
			pad.sample = Object.assign({}, pad.sample || {}, sample);
			pad.runtime = pad.runtime || {};
			pad.runtime.buffer = sample.buffer;
			this.markModified();
		}

		updatePad(bankIndex, padIndex, updates) {
			const bank = this.project.state.banks[bankIndex];
			if (!bank) {
				return;
			}
			const pad = bank.pads[padIndex];
			if (!pad) {
				return;
			}
			Object.assign(pad, updates);
			this.markModified();
		}

		updateSequenceSettings(index, updates) {
			const sequence = this.project.state.sequences[index];
			if (!sequence) {
				return;
			}
			Object.assign(sequence, updates);
			this.markModified();
		}

		refreshRuntimeBuffers(engine) {
			this.project.state.banks.forEach((bank) => {
				bank.pads.forEach((pad) => {
					if (pad.sample && pad.sample.src && !pad.sample.buffer) {
						engine.ensureContext();
						fetch(pad.sample.src)
							.then((resp) => resp.arrayBuffer())
							.then((buf) => engine.audioContext.decodeAudioData(buf))
							.then((audioBuffer) => {
								pad.sample.buffer = audioBuffer;
							});
					}
				});
			});
		}

		getSerializableState() {
			const clone = JSON.parse(JSON.stringify(this.project.state));
			clone.banks.forEach((bank) => {
				bank.pads.forEach((pad) => {
					if (pad.sample) {
						delete pad.sample.buffer;
					}
				});
			});
			return clone;
		}
	}

	class MPCUI {
		constructor(options) {
			this.root = options.root;
			this.context = options.context;
			this.bus = options.bus;
			this.engine = options.engine;
			this.store = new MPCProjectStore(options.project);
			this.rest = options.rest || null;
			this.projects = options.projects || [];
			this.settings = options.settings || {};
			this.sequencer = new MPCSequencer(this.engine, this.bus);
			this.currentPadSelection = { bankIndex: 0, padIndex: 0 };
			this.busListenersAttached = false;
			this.init();
		}

		init() {
			this.renderLayout();
			this.bindTransport();
			this.bindPadGrid();
			this.bindPadControls();
			this.bindSequencerGrid();
			this.bindSongMode();
			this.bindProjectSidebar();
			this.attachBusListeners();
			this.refreshFromState();
		}

		renderLayout() {
			const hasSidebar = this.context === 'admin';
			this.root.classList.add('akai-mpc1000-shell');
			this.root.innerHTML = `
				<div class="mpc-container">
					${hasSidebar ? this.renderSidebar() : ''}
					<div class="mpc-main">
						${this.renderTransport()}
						${this.renderPadBank()}
						${this.renderPadEditor()}
						${this.renderSequencer()}
						${this.renderSongMode()}
					</div>
				</div>
			`;
		}

		renderSidebar() {
			return `
				<aside class="mpc-sidebar">
					<header>
						<h2>Projects</h2>
						<button class="mpc-btn mpc-btn-primary" data-action="new-project">New Project</button>
					</header>
					<ul class="mpc-project-list"></ul>
				</aside>
			`;
		}

		renderTransport() {
			const state = this.store.state.state || this.store.state;
			return `
				<section class="mpc-transport">
					<div class="transport-controls">
						<button class="mpc-btn" data-transport="play">Play</button>
						<button class="mpc-btn" data-transport="stop">Stop</button>
						<button class="mpc-btn" data-transport="record">Rec</button>
					</div>
					<div class="transport-settings">
						<label>Tempo <input type="number" min="40" max="240" value="${state.global.tempo}" data-setting="tempo"></label>
						<label>Swing <input type="number" min="50" max="75" value="${state.global.swing}" data-setting="swing"></label>
						<label>Quantize
							<select data-setting="quantize">
								<option value="8">1/8</option>
								<option value="16" selected>1/16</option>
								<option value="32">1/32</option>
							</select>
						</label>
						<label>
							<input type="checkbox" data-setting="metronome"> Metronome
						</label>
					</div>
				</section>
			`;
		}

		renderPadBank() {
			const bank = this.store.getActiveBank();
			if (!bank) {
				return '<section class="mpc-padbanks">No banks loaded</section>';
			}
			const bankTabs = this.store.state.state.banks
				.map((b, index) => `<button class="mpc-tab ${index === this.store.state.runtime.activeBank ? 'is-active' : ''}" data-bank-index="${index}">${b.name}</button>`)
				.join('');
			const pads = bank.pads
				.map((pad, index) => `
					<button class="mpc-pad" data-pad-index="${index}" data-bank-index="${this.store.state.runtime.activeBank}" style="--pad-color:${pad.color};">
						<span class="pad-label">${pad.label}</span>
						<span class="pad-sample">${pad.sample ? pad.sample.name : '---'}</span>
					</button>
				`)
				.join('');
			return `
				<section class="mpc-padbanks">
					<div class="mpc-bank-tabs">${bankTabs}</div>
					<div class="mpc-pad-grid">${pads}</div>
				</section>
			`;
		}

		renderPadEditor() {
			const pad = this.getSelectedPad();
			return `
				<section class="mpc-pad-editor">
					<header>
						<h3>Pad Settings</h3>
						<div class="mpc-editor-actions">
							<label class="mpc-btn mpc-btn-secondary file">
								Load Sample
								<input type="file" accept="audio/*" data-action="load-sample" hidden>
							</label>
							<button class="mpc-btn" data-action="record-sample">Sample</button>
						</div>
					</header>
					<div class="mpc-pad-details">
						<div class="mpc-field">
							<label>Name</label>
							<input type="text" value="${pad.sample ? pad.sample.name : pad.label}" data-pad-setting="name">
						</div>
						<div class="mpc-field triple">
							<label>Start</label>
							<input type="number" min="0" step="0.001" value="${pad.sample ? pad.sample.start.toFixed(3) : 0}" data-pad-setting="start">
							<label>End</label>
							<input type="number" min="0" step="0.001" value="${pad.sample ? pad.sample.end.toFixed(3) : 0}" data-pad-setting="end">
						</div>
						<div class="mpc-field triple">
							<label>Gain</label>
							<input type="range" min="0" max="1" step="0.01" value="${pad.sample ? pad.sample.gain : 0.8}" data-pad-setting="gain">
							<label>Pan</label>
							<input type="range" min="-1" max="1" step="0.01" value="${pad.pan}" data-pad-setting="pan">
						</div>
						<div class="mpc-field triple">
							<label>Attack</label>
							<input type="range" min="0" max="1" step="0.01" value="${pad.adsr.attack}" data-pad-envelope="attack">
							<label>Decay</label>
							<input type="range" min="0" max="2" step="0.01" value="${pad.adsr.decay}" data-pad-envelope="decay">
							<label>Release</label>
							<input type="range" min="0" max="3" step="0.01" value="${pad.adsr.release}" data-pad-envelope="release">
						</div>
						<div class="mpc-field">
							<label>Tune</label>
							<input type="number" min="-12" max="12" step="0.1" value="${pad.sample ? pad.sample.tune : 0}" data-pad-setting="tune">
						</div>
					</div>
				</section>
			`;
		}

		renderSequencer() {
			const sequence = this.store.getActiveSequence();
			if (!sequence) {
				return '<section class="mpc-sequencer">No sequence</section>';
			}

			const tracks = sequence.tracks.slice(0, 8);
			const activeTrackId = sequence.activeTrack || (tracks[0] ? tracks[0].id : null);
			const totalSteps = sequence.grid.length;
			const stepsPerBar = 16;
			const bars = totalSteps / stepsPerBar;
			const stepHeaders = Array.from({ length: totalSteps }).map((_, index) => {
				const barIndex = Math.floor(index / 16) + 1;
				const stepInBar = (index % 16) + 1;
				return `<div class="mpc-step-header" data-step="${index}">${barIndex}:${stepInBar.toString().padStart(2, '0')}</div>`;
			}).join('');

			const rows = tracks.map((track) => {
				const cells = Array.from({ length: totalSteps }).map((_, index) => {
					const active = sequence.grid[index].events.some((evt) => evt.trackId === track.id);
					return `<button class="mpc-step ${active ? 'is-active' : ''}" data-track="${track.id}" data-step="${index}"></button>`;
				}).join('');
				return `
					<div class="mpc-track-row ${track.id === activeTrackId ? 'is-active' : ''}" data-track="${track.id}">
						<div class="mpc-track-label">
							<span>${track.name}</span>
							<input type="text" value="${track.program}" data-track-program="${track.id}">
						</div>
						<div class="mpc-track-grid">${cells}</div>
					</div>
				`;
			}).join('');

			return `
				<section class="mpc-sequencer">
					<header>
						<h3>${sequence.name}</h3>
						<div class="mpc-sequence-controls">
							<label>Bars
								<input type="number" min="1" max="${MAX_BARS}" value="${sequence.bars}" data-sequence-setting="bars">
							</label>
							<button class="mpc-btn" data-action="duplicate-sequence">Duplicate</button>
						</div>
					</header>
					<div class="mpc-step-headers">${stepHeaders}</div>
					<div class="mpc-sequencer-grid">${rows}</div>
				</section>
			`;
		}

		renderSongMode() {
			const chain = this.store.state.state.song.chain || [];
			const rows = chain.map((row, index) => `
				<li data-song-index="${index}">
					<span>${row.sequence} ? ${row.repeats}</span>
					<button data-action="remove-chain" data-song-index="${index}">?</button>
				</li>
			`).join('');
			return `
				<section class="mpc-song">
					<header>
						<h3>Song Mode</h3>
					</header>
					<div class="mpc-song-form">
						<select data-song-sequence>
							${this.store.state.state.sequences.map((seq) => `<option value="${seq.id}">${seq.name}</option>`).join('')}
						</select>
						<input type="number" min="1" max="16" value="1" data-song-repeats>
						<button class="mpc-btn" data-action="add-to-song">Add</button>
					</div>
					<ul class="mpc-song-list">${rows}</ul>
				</section>
			`;
		}

		bindTransport() {
			const transport = this.root.querySelector('.mpc-transport');
			if (!transport) {
				return;
			}
			transport.addEventListener('click', async (event) => {
				const target = event.target.closest('[data-transport]');
				if (!target) {
					return;
				}
				switch (target.dataset.transport) {
					case 'play':
						await this.engine.ensureContext();
						this.sequencer.start();
						break;
					case 'stop':
						this.sequencer.stop();
						break;
					case 'record':
						await this.engine.ensureContext();
						this.sequencer.toggleRecord();
						break;
					default:
						break;
				}
			});

			transport.addEventListener('change', (event) => {
				const target = event.target;
				const setting = target.dataset.setting;
				const state = this.store.state.state || this.store.state;
				if (!setting) {
					return;
				}
				switch (setting) {
					case 'tempo':
						state.global.tempo = parseInt(target.value, 10);
						this.sequencer.setTempo(state.global.tempo);
						this.store.markModified();
						break;
					case 'swing':
						state.global.swing = parseInt(target.value, 10);
						this.sequencer.setSwing(state.global.swing);
						this.store.markModified();
						break;
					case 'quantize':
						state.global.quantize = parseInt(target.value, 10);
						this.sequencer.setQuantize(state.global.quantize);
						this.store.markModified();
						break;
					case 'metronome':
						this.sequencer.enableMetronome(target.checked);
						break;
					default:
						break;
				}
			});
		}

		bindPadGrid() {
			const padGrid = this.root.querySelector('.mpc-pad-grid');
			const bankTabs = this.root.querySelector('.mpc-bank-tabs');

			if (bankTabs) {
				bankTabs.addEventListener('click', (event) => {
					const tab = event.target.closest('[data-bank-index]');
					if (!tab) {
						return;
					}
					const bankIndex = parseInt(tab.dataset.bankIndex, 10);
					this.store.setActiveBank(bankIndex);
					this.currentPadSelection = { bankIndex, padIndex: 0 };
					this.refreshPadBank();
					this.refreshPadEditor();
				});
			}

			if (padGrid) {
				padGrid.addEventListener('click', async (event) => {
					const padBtn = event.target.closest('.mpc-pad');
					if (!padBtn) {
						return;
					}
					const bankIndex = parseInt(padBtn.dataset.bankIndex, 10);
					const padIndex = parseInt(padBtn.dataset.padIndex, 10);
					this.currentPadSelection = { bankIndex, padIndex };
					const pad = this.getSelectedPad();
					if (pad.sample && pad.sample.buffer) {
						this.engine.playPad(pad);
					}
					this.sequencer.capturePadEvent(pad.id, 1);
					this.refreshPadEditor();
				});
			}
		}

		bindPadControls() {
			const padEditor = this.root.querySelector('.mpc-pad-editor');
			if (!padEditor) {
				return;
			}

			padEditor.addEventListener('change', async (event) => {
				const target = event.target;
				const pad = this.getSelectedPad();
				if (!pad) {
					return;
				}

				if (target.dataset.padSetting) {
					switch (target.dataset.padSetting) {
						case 'name':
							if (pad.sample) {
								pad.sample.name = target.value;
							}
							break;
						case 'start':
							if (pad.sample) {
								pad.sample.start = Math.max(0, parseFloat(target.value));
							}
							break;
						case 'end':
							if (pad.sample) {
								pad.sample.end = Math.max(0, parseFloat(target.value));
							}
							break;
						case 'gain':
							if (pad.sample) {
								pad.sample.gain = parseFloat(target.value);
							}
							break;
						case 'pan':
							pad.pan = parseFloat(target.value);
							break;
						case 'tune':
							if (pad.sample) {
								pad.sample.tune = parseFloat(target.value);
							}
							break;
						default:
							break;
					}
				}

				if (target.dataset.padEnvelope) {
					pad.adsr[target.dataset.padEnvelope] = parseFloat(target.value);
				}

				this.store.markModified();
			});

			padEditor.addEventListener('click', async (event) => {
				const target = event.target.closest('[data-action]');
				if (!target) {
					return;
				}
				if (target.dataset.action === 'record-sample') {
					target.disabled = true;
					target.textContent = 'Recording...';
					try {
						const sample = await this.engine.recordSample(10);
						const { bankIndex, padIndex } = this.currentPadSelection;
						this.store.setPadSample(bankIndex, padIndex, sample);
						this.refreshPadBank();
						this.refreshPadEditor();
					} catch (err) {
						console.error(err);
						alert('Failed to capture sample: ' + err.message);
					} finally {
						target.disabled = false;
						target.textContent = 'Sample';
					}
				}
			});

			const fileInput = padEditor.querySelector('input[type="file"][data-action="load-sample"]');
			if (fileInput) {
				fileInput.addEventListener('change', async (event) => {
					const file = event.target.files[0];
					if (!file) {
						return;
					}
					try {
						const sample = await this.engine.loadSampleFromFile(file);
						const { bankIndex, padIndex } = this.currentPadSelection;
						this.store.setPadSample(bankIndex, padIndex, sample);
						this.refreshPadBank();
						this.refreshPadEditor();
					} catch (err) {
						console.error(err);
						alert('Failed to load sample: ' + err.message);
					}
				});
			}
		}

	bindSequencerGrid() {
		const sequencerEl = this.root.querySelector('.mpc-sequencer');
		if (!sequencerEl) {
			return;
		}

		sequencerEl.addEventListener('click', (event) => {
			const stepButton = event.target.closest('.mpc-step');
			if (stepButton) {
				const stepIndex = parseInt(stepButton.dataset.step, 10);
				const trackId = stepButton.dataset.track;
				this.toggleStepEvent(trackId, stepIndex, stepButton);
				return;
			}

			const actionButton = event.target.closest('[data-action]');
			if (actionButton) {
				switch (actionButton.dataset.action) {
					case 'duplicate-sequence':
						this.duplicateSequence();
						break;
					default:
						break;
				}
				return;
			}

			const trackLabel = event.target.closest('.mpc-track-label');
			if (trackLabel && !event.target.matches('input')) {
				const trackRow = trackLabel.closest('.mpc-track-row');
				const trackId = trackRow ? trackRow.dataset.track : null;
				const sequence = this.store.getActiveSequence();
				if (trackId && sequence) {
					sequence.activeTrack = trackId;
					this.store.markModified();
					this.updateActiveTrackHighlight(trackId);
				}
			}
		});

		sequencerEl.addEventListener('change', (event) => {
			const target = event.target;
			if (target.dataset.sequenceSetting === 'bars') {
				const value = Math.min(MAX_BARS, Math.max(1, parseInt(target.value, 10)));
				const sequence = this.store.getActiveSequence();
				if (!sequence) {
					return;
				}
				const currentLength = sequence.grid.length;
				const desiredSteps = value * 16;
				if (desiredSteps > currentLength) {
					for (let i = currentLength; i < desiredSteps; i++) {
						sequence.grid.push({ index: i, events: [], accent: 0, swing: null });
					}
				} else if (desiredSteps < currentLength) {
					sequence.grid = sequence.grid.slice(0, desiredSteps);
				}
				sequence.bars = value;
				this.store.markModified();
				this.refreshSequencer();
				return;
			}

			if (target.dataset.trackProgram) {
				const sequence = this.store.getActiveSequence();
				if (!sequence) {
					return;
				}
				const track = sequence.tracks.find((trk) => trk.id === target.dataset.trackProgram);
				if (track) {
					track.program = target.value;
					this.store.markModified();
				}
			}
		});
	}

		bindProjectSidebar() {
			if (this.context !== 'admin') {
				return;
			}
			const sidebar = this.root.querySelector('.mpc-sidebar');
			const list = sidebar.querySelector('.mpc-project-list');
			this.renderProjectList();

			sidebar.addEventListener('click', (event) => {
				const button = event.target.closest('button');
				if (!button) {
					return;
				}
				if (button.dataset.action === 'new-project') {
					this.createNewProject();
					return;
				}
				if (button.dataset.projectId) {
					const project = this.projects.find((p) => p.id === parseInt(button.dataset.projectId, 10));
					if (project) {
						this.loadProject(project);
					}
				}
				if (button.dataset.action === 'save-project') {
					this.saveProject();
				}
			});
		}

		attachBusListeners() {
			if (this.busListenersAttached) {
				return;
			}
			this.bus.on('sequence:event', ({ event }) => {
				const pad = this.findPadById(event.padId);
				if (pad) {
					this.engine.playPad(pad, { velocity: event.velocity || 1 });
				}
			});
			this.bus.on('sequence:step', ({ stepIndex }) => {
				this.highlightStep(stepIndex);
			});
			this.busListenersAttached = true;
		}

		refreshFromState() {
			const state = this.store.state.state || this.store.state;
			this.engine.setMasterVolume(state.global.volume);
			this.sequencer.setTempo(state.global.tempo);
			this.sequencer.setSwing(state.global.swing);
			this.sequencer.setQuantize(state.global.quantize);
			this.store.refreshRuntimeBuffers(this.engine);
			const activeSequence = this.store.getActiveSequence();
			if (activeSequence && !activeSequence.activeTrack && activeSequence.tracks.length) {
				activeSequence.activeTrack = activeSequence.tracks[0].id;
			}
			this.sequencer.loadSequence(activeSequence);
			if (activeSequence && activeSequence.activeTrack) {
				this.updateActiveTrackHighlight(activeSequence.activeTrack);
			}
		}

		refreshPadBank() {
			const padbanks = this.root.querySelector('.mpc-padbanks');
			padbanks.outerHTML = this.renderPadBank();
			this.bindPadGrid();
		}

		refreshPadEditor() {
			const editor = this.root.querySelector('.mpc-pad-editor');
			editor.outerHTML = this.renderPadEditor();
			this.bindPadControls();
		}

		refreshSequencer() {
			const sequencer = this.root.querySelector('.mpc-sequencer');
			sequencer.outerHTML = this.renderSequencer();
			this.bindSequencerGrid();
			const sequence = this.store.getActiveSequence();
			this.sequencer.loadSequence(sequence);
			if (sequence && sequence.activeTrack) {
				this.updateActiveTrackHighlight(sequence.activeTrack);
			}
		}

		getSelectedPad() {
			const { bankIndex, padIndex } = this.currentPadSelection;
			const bank = this.store.state.state.banks[bankIndex];
			if (!bank) {
				return null;
			}
			return bank.pads[padIndex];
		}

		toggleStepEvent(trackId, stepIndex, button) {
			const sequence = this.store.getActiveSequence();
			const step = sequence.grid[stepIndex];
			const existing = step.events.find((evt) => evt.trackId === trackId);
			if (existing) {
				step.events = step.events.filter((evt) => evt !== existing);
				button.classList.remove('is-active');
			} else {
				const pad = this.getSelectedPad();
				if (!pad) {
					return;
				}
				step.events.push({ trackId, padId: pad.id, velocity: 1, length: 1 });
				button.classList.add('is-active');
			}
			this.store.markModified();
		}

		duplicateSequence() {
			const sequence = this.store.getActiveSequence();
			if (!sequence) {
				return;
			}
			this.store.addSequence(sequence);
			this.refreshSequencer();
			this.renderSongModeSection();
		}

		updateActiveTrackHighlight(trackId) {
			const rows = this.root.querySelectorAll('.mpc-track-row');
			rows.forEach((row) => {
				row.classList.toggle('is-active', row.dataset.track === trackId);
			});
		}

		renderSongModeSection() {
			const songSection = this.root.querySelector('.mpc-song');
			if (!songSection) {
				return;
			}
			songSection.outerHTML = this.renderSongMode();
			this.bindSongMode();
		}

		bindSongMode() {
			const songSection = this.root.querySelector('.mpc-song');
			if (!songSection) {
				return;
			}

			songSection.addEventListener('click', (event) => {
				const actionButton = event.target.closest('[data-action]');
				if (!actionButton) {
					return;
				}
				const sequenceState = this.store.state.state;
				switch (actionButton.dataset.action) {
					case 'add-to-song': {
						const select = songSection.querySelector('[data-song-sequence]');
						const repeatsEl = songSection.querySelector('[data-song-repeats]');
						if (!select || !repeatsEl) {
							return;
						}
						const repeats = Math.max(1, Math.min(16, parseInt(repeatsEl.value, 10) || 1));
						sequenceState.song.chain.push({ sequence: select.value, repeats });
						this.store.markModified();
						this.renderSongModeSection();
						break;
					}
					case 'remove-chain': {
						const index = parseInt(actionButton.dataset.songIndex, 10);
						if (!Number.isNaN(index)) {
							sequenceState.song.chain.splice(index, 1);
							this.store.markModified();
							this.renderSongModeSection();
						}
						break;
					}
					default:
						break;
				}
			});
		}

		highlightStep(stepIndex) {
			const previous = this.root.querySelector('.mpc-step.current');
			if (previous) {
				previous.classList.remove('current');
			}
			const current = this.root.querySelector(`.mpc-step[data-step="${stepIndex}"]`);
			if (current) {
				current.classList.add('current');
			}
		}

		findPadById(padId) {
			for (const bank of this.store.state.state.banks) {
				for (const pad of bank.pads) {
					if (pad.id === padId) {
						return pad;
					}
				}
			}
			return null;
		}

		renderProjectList() {
			if (this.context !== 'admin') {
				return;
			}
			const list = this.root.querySelector('.mpc-project-list');
			if (!list) {
				return;
			}
			list.innerHTML = this.projects
				.map((project) => `
					<li>
						<button data-project-id="${project.id}">${project.title}</button>
					</li>
				`)
				.join('');
			const sidebar = this.root.querySelector('.mpc-sidebar');
			if (sidebar) {
				const existingSave = sidebar.querySelector('button[data-action="save-project"]');
				if (existingSave) {
					existingSave.remove();
				}
				const saveButton = document.createElement('button');
				saveButton.textContent = 'Save Project';
				saveButton.className = 'mpc-btn mpc-btn-primary';
				saveButton.dataset.action = 'save-project';
				sidebar.appendChild(saveButton);
			}
		}

		async createNewProject() {
			const name = prompt('Project title', 'New MPC Project');
			if (!name) {
				return;
			}
			if (!this.rest) {
				return;
			}
			const payload = {
				title: name,
				project_state: this.store.getSerializableState(),
			};
			const result = await this.apiRequest('POST', '/projects', payload);
			this.projects.unshift(result);
			this.loadProject(result);
			this.renderProjectList();
		}

		async saveProject() {
			if (!this.rest || !this.store.state.id) {
				return;
			}
			const payload = {
				title: this.store.state.title,
				project_state: this.store.getSerializableState(),
				performance_notes: this.store.state.performance || '',
			};
			const saved = await this.apiRequest('POST', `/projects/${this.store.state.id}`, payload);
			this.store.project = JSON.parse(JSON.stringify(saved));
			this.store.attachRuntime();
			this.refreshFromState();
			alert('Project saved');
		}

		async loadProject(project) {
			this.store.project = JSON.parse(JSON.stringify(project));
			this.store.attachRuntime();
			this.refreshFromState();
			this.renderLayout();
			this.bindTransport();
			this.bindPadGrid();
			this.bindPadControls();
			this.bindSequencerGrid();
			this.bindSongMode();
			this.bindProjectSidebar();
			this.attachBusListeners();
		}

		async apiRequest(method, path, payload) {
			const response = await fetch(`${this.rest.root}${path}`, {
				method,
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': this.rest.nonce,
				},
				body: payload ? JSON.stringify(payload) : undefined,
			});
			if (!response.ok) {
				const message = await response.text();
				throw new Error(message);
			}
			return response.json();
		}
	}

	function bootstrapInstance(root, payload) {
		const bus = new EventBus();
		const engine = new MPCAudioEngine(bus);
		const projects = payload.projects || [];
		const project = payload.project || projects[0] || {
			id: 0,
			title: 'Untitled',
			state: payload.settings ? payload.settings.defaultState : payload.defaultState,
		};

		new MPCUI({
			root,
			context: payload.context || 'embed',
			bus,
			engine,
			projects,
			project,
			rest: payload.rest || null,
			settings: payload.settings || {},
		});
	}

	function bootstrapFromGlobalConfig() {
		const adminRoot = document.getElementById('akai-mpc1000-app');
		if (adminRoot && window.akaiMPC1000AppConfig) {
			bootstrapInstance(adminRoot, window.akaiMPC1000AppConfig);
		}

		const embeds = document.querySelectorAll('.akai-mpc1000-embed');
		if (embeds.length) {
			const bootstraps = window.AkaiMPC1000Bootstraps || {};
			embeds.forEach((embed) => {
				const key = embed.dataset.instance;
				const payload = bootstraps[key];
				if (payload) {
					bootstrapInstance(embed, payload);
				}
			});
		}
	}

	document.addEventListener('DOMContentLoaded', bootstrapFromGlobalConfig);

	window.AkaiMPC1000 = {
		reboot(rootId, payload) {
			const root = document.getElementById(rootId);
			if (!root) {
				throw new Error('Root element not found');
			}
			bootstrapInstance(root, payload);
		},
	};
})(window, document);
