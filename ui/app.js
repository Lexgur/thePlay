let project = null;
let draft = null;
let arming = false;
let audioCtx = null;
const buffers = new Map();
const active = new Map();

const doc = document.getElementById('doc');
const editor = document.getElementById('editor');
const quote = document.getElementById('quote');
const keyLabel = document.getElementById('key-label');
const urlInput = document.getElementById('url');
const startInput = document.getElementById('start');
const endInput = document.getElementById('end');
const fadeInput = document.getElementById('fade');
const fadeOutInput = document.getElementById('fade-out');
const volumeInput = document.getElementById('volume');
const saveButton = document.getElementById('save');
const addButton = document.getElementById('add');
const removeButton = document.getElementById('remove');
const soundList = document.getElementById('sound-list');
const status = document.getElementById('status');
const fileName = document.getElementById('file-name');
const scenes = document.getElementById('scenes');
const slideTitle = document.getElementById('slide-title');
let slideIndex = 0;

document.getElementById('open').addEventListener('click', async () => {
  if (!window.thePlay) {
    setStatus('Open a script from the thePlay window.');
    return;
  }
  const filePath = await window.thePlay.openFile();
  if (!filePath) {
    return;
  }
  setStatus('Opening…');
  const data = await postJson('/api/open', { path: filePath });
  if (!data) {
    return;
  }
  project = data;
  draft = null;
  slideIndex = 0;
  editor.hidden = true;
  fileName.textContent = filePath.split(/[/\\]/).pop();
  setStatus('');
  render();
});

document.getElementById('prev').addEventListener('click', () => changeSlide(-1));
document.getElementById('next').addEventListener('click', () => changeSlide(1));

document.getElementById('set-key').addEventListener('click', () => {
  arming = true;
  keyLabel.textContent = 'Press a key…';
});

saveButton.addEventListener('click', saveCue);
addButton.addEventListener('click', addAnother);
removeButton.addEventListener('click', removeCue);

doc.addEventListener('mouseup', () => {
  if (!project) {
    return;
  }
  const selection = window.getSelection();
  if (!selection || selection.rangeCount === 0) {
    return;
  }
  const anchor = selection.anchorNode;
  const element = anchor && (anchor.nodeType === 1 ? anchor : anchor.parentElement);
  if (selection.isCollapsed) {
    const mark = element && element.closest('mark');
    if (!mark) {
      return;
    }
    const cueId = (mark.dataset.ids || '').split(',')[0];
    const cue = project.cues.find((item) => item.id === cueId);
    if (cue) {
      showCue(cue);
    }
    return;
  }

  const range = selection.getRangeAt(0);
  const startParagraph = paragraphOf(range.startContainer);
  const endParagraph = paragraphOf(range.endContainer);
  if (!startParagraph || startParagraph !== endParagraph) {
    setStatus('Select text inside a single paragraph.');
    return;
  }
  const before = document.createRange();
  before.setStart(startParagraph, 0);
  before.setEnd(range.startContainer, range.startOffset);
  const startOffset = before.toString().length;
  const endOffset = startOffset + range.toString().length;
  if (endOffset <= startOffset) {
    return;
  }
  showDraft({
    id: null,
    paragraphId: startParagraph.dataset.id,
    startOffset,
    endOffset,
    key: '',
    youtubeUrl: '',
    start: '',
    end: '',
    fadeIn: 2,
    fadeOut: 2,
    volume: 100,
  });
});

window.addEventListener('keydown', (event) => {
  if (arming) {
    event.preventDefault();
    if (event.key === 'Escape' || event.key === 'Shift' || event.key === 'Control' || event.key === 'Alt' || event.key === 'Meta') {
      if (event.key === 'Escape') {
        arming = false;
        keyLabel.textContent = labelFor(draft ? draft.key : '');
      }
      return;
    }
    arming = false;
    if (draft) {
      draft.key = event.key;
    }
    keyLabel.textContent = labelFor(event.key);
    return;
  }
  if (event.repeat || event.metaKey || event.ctrlKey || event.altKey) {
    return;
  }
  if (event.target.closest('input, textarea, select, #editor button')) {
    return;
  }
  if (!project) {
    return;
  }
  if (event.key === 'PageDown') {
    event.preventDefault();
    changeSlide(1);
    return;
  }
  if (event.key === 'PageUp') {
    event.preventDefault();
    changeSlide(-1);
    return;
  }
  const cue = project.cues.find((item) => item.key === event.key);
  if (!cue) {
    return;
  }
  event.preventDefault();
  showSlideForParagraph(cue.paragraphId);
  play(cue);
});

function showCue(cue) {
  showDraft(cue);
}

function showDraft(cue) {
  draft = {
    id: cue.id,
    paragraphId: cue.paragraphId,
    startOffset: cue.startOffset,
    endOffset: cue.endOffset,
    key: cue.key || '',
    youtubeUrl: cue.youtubeUrl || '',
    start: cue.start || '',
    end: cue.end || '',
    fadeIn: cue.fadeIn ?? 2,
    fadeOut: cue.fadeOut ?? cue.fadeIn ?? 2,
    volume: cue.volume ?? 100,
  };
  const paragraph = project.paragraphs.find((item) => item.id === draft.paragraphId);
  quote.textContent = paragraph ? paragraph.text.slice(draft.startOffset, draft.endOffset) : '';
  keyLabel.textContent = labelFor(draft.key);
  urlInput.value = draft.youtubeUrl;
  startInput.value = draft.start;
  endInput.value = draft.end;
  fadeInput.value = String(draft.fadeIn);
  fadeOutInput.value = String(draft.fadeOut);
  volumeInput.value = String(draft.volume);
  removeButton.hidden = !draft.id;
  editor.hidden = false;
  renderSoundList();
  setStatus('');
}

function soundsOnDraft() {
  if (!project || !draft) {
    return [];
  }
  return project.cues.filter((cue) => cue.paragraphId === draft.paragraphId
    && cue.startOffset < draft.endOffset
    && draft.startOffset < cue.endOffset);
}

function renderSoundList() {
  soundList.replaceChildren();
  const sounds = soundsOnDraft();
  addButton.hidden = sounds.length === 0;
  for (const cue of sounds) {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = labelFor(cue.key);
    if (draft && cue.id === draft.id) {
      button.disabled = true;
    }
    button.addEventListener('click', () => showCue(cue));
    soundList.appendChild(button);
  }
}

function addAnother() {
  if (!draft) {
    return;
  }
  showDraft({
    id: null,
    paragraphId: draft.paragraphId,
    startOffset: draft.startOffset,
    endOffset: draft.endOffset,
    key: '',
    youtubeUrl: '',
    start: '',
    end: '',
    fadeIn: 2,
    fadeOut: 2,
    volume: 100,
  });
  setStatus('Set the next sound, then save.');
}

async function saveCue() {
  if (!project || !draft) {
    return;
  }
  if (!draft.key) {
    setStatus('Press a key for this sound.');
    return;
  }
  saveButton.disabled = true;
  setStatus('Downloading the YouTube audio…');
  const data = await postJson('/api/cue', {
    projectId: project.id,
    id: draft.id,
    paragraphId: draft.paragraphId,
    startOffset: draft.startOffset,
    endOffset: draft.endOffset,
    key: draft.key,
    youtubeUrl: urlInput.value.trim(),
    start: startInput.value.trim(),
    end: endInput.value.trim(),
    fadeIn: Number(fadeInput.value),
    fadeOut: Number(fadeOutInput.value),
    volume: Number(volumeInput.value),
  });
  saveButton.disabled = false;
  if (!data) {
    return;
  }
  const match = {
    paragraphId: draft.paragraphId,
    startOffset: draft.startOffset,
    endOffset: draft.endOffset,
    key: draft.key,
  };
  project = data;
  render();
  const saved = project.cues.find((item) => item.paragraphId === match.paragraphId
    && item.startOffset === match.startOffset
    && item.endOffset === match.endOffset
    && item.key === match.key);
  if (saved) {
    showCue(saved);
  }
  setStatus('Saved. Press ' + labelFor(saved ? saved.key : draft.key) + ' to play.');
}

async function removeCue() {
  if (!project || !draft || !draft.id) {
    return;
  }
  const data = await sendJson('/api/cue', 'DELETE', { projectId: project.id, id: draft.id });
  if (!data) {
    return;
  }
  project = data;
  draft = null;
  editor.hidden = true;
  setStatus('');
  render();
}

function plainTitle(text) {
  return text.trim().replace(/^(?:\p{Extended_Pictographic}|\p{Regional_Indicator}{2})+\s*/u, '').trim();
}

function isScene(text, next, index, startAt) {
  if (index < startAt) {
    return false;
  }
  const trimmed = text.trim();
  const plain = plainTitle(trimmed);
  if (plain === 'PABAIGA') {
    return true;
  }
  if (!/^(?:\p{Extended_Pictographic}|\p{Regional_Indicator}{2})/u.test(trimmed)) {
    return false;
  }
  if (!plain || plain.length > 28 || plain !== plain.toLocaleUpperCase('lt') || /[().]/.test(plain)) {
    return false;
  }
  if (next && next.trim().length > 70) {
    return false;
  }
  return true;
}

function buildSlides(paragraphs) {
  const startAt = paragraphs.findIndex((paragraph) => plainTitle(paragraph.text) === 'PRADŽIA');
  const from = startAt === -1 ? 0 : startAt;
  const slides = [];
  let current = null;
  paragraphs.forEach((paragraph, index) => {
    const next = paragraphs[index + 1];
    if (current === null || isScene(paragraph.text, next && next.text, index, from)) {
      current = {
        title: plainTitle(paragraph.text),
        paragraphs: [],
      };
      slides.push(current);
    }
    current.paragraphs.push(paragraph);
  });
  return slides;
}

function changeSlide(delta) {
  if (!project) {
    return;
  }
  const count = buildSlides(project.paragraphs).length;
  const next = Math.min(count - 1, Math.max(0, slideIndex + delta));
  if (next === slideIndex) {
    return;
  }
  slideIndex = next;
  render();
}

function showSlideForParagraph(paragraphId) {
  if (!project) {
    return;
  }
  const index = buildSlides(project.paragraphs).findIndex((slide) => {
    return slide.paragraphs.some((paragraph) => paragraph.id === paragraphId);
  });
  if (index >= 0 && index !== slideIndex) {
    slideIndex = index;
    render();
  }
}

function render() {
  if (!project) {
    return;
  }
  const slides = buildSlides(project.paragraphs);
  if (slideIndex >= slides.length) {
    slideIndex = slides.length - 1;
  }
  const slide = slides[slideIndex];
  slideTitle.textContent = slide.title;
  scenes.replaceChildren();
  slides.forEach((item, index) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = item.title;
    button.classList.toggle('active', index === slideIndex);
    button.addEventListener('click', () => {
      slideIndex = index;
      render();
    });
    scenes.appendChild(button);
  });
  doc.replaceChildren();
  const heading = document.createElement('h1');
  heading.textContent = slide.title;
  doc.appendChild(heading);
  const visible = slide.paragraphs.filter((paragraph, index) => {
    return !(index === 0 && plainTitle(paragraph.text) === slide.title);
  });
  for (const paragraph of visible) {
    const element = document.createElement('p');
    element.dataset.id = paragraph.id;
    const cues = project.cues.filter((cue) => cue.paragraphId === paragraph.id);
    const points = [0, paragraph.text.length];
    for (const cue of cues) {
      points.push(cue.startOffset, cue.endOffset);
    }
    const stops = [...new Set(points)].filter((point) => point >= 0 && point <= paragraph.text.length).sort((a, b) => a - b);
    for (let index = 0; index < stops.length - 1; index += 1) {
      const start = stops[index];
      const end = stops[index + 1];
      const text = paragraph.text.slice(start, end);
      const covering = cues.filter((cue) => cue.startOffset <= start && cue.endOffset >= end);
      if (covering.length === 0) {
        appendText(element, text);
        continue;
      }
      const mark = document.createElement('mark');
      mark.dataset.ids = covering.map((cue) => cue.id).join(',');
      mark.dataset.key = covering.map((cue) => labelFor(cue.key)).join(' ');
      mark.textContent = text;
      element.appendChild(mark);
    }
    doc.appendChild(element);
  }
}

function appendText(parent, text) {
  if (text) {
    parent.appendChild(document.createTextNode(text));
  }
}

function paragraphOf(node) {
  const element = node.nodeType === 1 ? node : node.parentElement;
  return element ? element.closest('p[data-id]') : null;
}

function labelFor(key) {
  if (!key) {
    return 'none';
  }
  if (key === ' ') {
    return 'Space';
  }
  return key;
}

async function play(cue) {
  const ctx = audioCtx || new AudioContext();
  audioCtx = ctx;
  if (ctx.state === 'suspended') {
    await ctx.resume();
  }
  let buffer = buffers.get(cue.clip);
  if (!buffer) {
    const response = await fetch('/audio/' + encodeURIComponent(cue.clip));
    if (!response.ok) {
      setStatus('Could not play that clip.');
      return;
    }
    buffer = await ctx.decodeAudioData(await response.arrayBuffer());
    buffers.set(cue.clip, buffer);
  }
  const previous = active.get(cue.key);
  if (previous) {
    try {
      previous.stop();
    } catch (error) {
      /* already stopped */
    }
  }
  const source = ctx.createBufferSource();
  const gain = ctx.createGain();
  source.buffer = buffer;
  source.connect(gain);
  gain.connect(ctx.destination);
  const now = ctx.currentTime;
  const duration = buffer.duration;
  const fadeIn = Math.min(Math.max(0, Number(cue.fadeIn) || 0), duration);
  const fadeOut = Math.min(Math.max(0, Number(cue.fadeOut ?? cue.fadeIn) || 0), Math.max(0, duration - fadeIn));
  const peak = Math.max(0, Math.min(1, (Number(cue.volume) || 0) / 100));
  gain.gain.setValueAtTime(0, now);
  if (fadeIn === 0) {
    gain.gain.setValueAtTime(peak, now);
  } else {
    gain.gain.linearRampToValueAtTime(peak, now + fadeIn);
  }
  if (fadeOut > 0) {
    gain.gain.setValueAtTime(peak, now + duration - fadeOut);
    gain.gain.linearRampToValueAtTime(0, now + duration);
  }
  source.start(now);
  source.stop(now + buffer.duration);
  active.set(cue.key, source);
  source.onended = () => {
    if (active.get(cue.key) === source) {
      active.delete(cue.key);
    }
  };
}

async function postJson(url, body) {
  return sendJson(url, 'POST', body);
}

async function sendJson(url, method, body) {
  let response;
  try {
    response = await fetch(url, {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
  } catch (error) {
    setStatus('The app server is not responding.');
    return null;
  }
  const data = await response.json().catch(() => null);
  if (!response.ok) {
    setStatus(data && data.error ? data.error : 'Something went wrong.');
    return null;
  }
  return data;
}

function setStatus(message) {
  status.textContent = message;
}
