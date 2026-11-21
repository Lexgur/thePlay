const video = document.getElementById('video');
const picture = document.getElementById('picture');
const controls = document.getElementById('controls');
const screens = document.getElementById('screens');
let idleTimer = 0;

function show(visual) {
  if (!visual || !visual.src) {
    return;
  }
  if (visual.kind === 'image') {
    video.pause();
    video.removeAttribute('src');
    video.hidden = true;
    picture.hidden = false;
    picture.src = visual.src;
    return;
  }
  picture.hidden = true;
  picture.removeAttribute('src');
  video.hidden = false;
  video.loop = !!visual.loop;
  video.src = visual.src;
  video.play();
}

function rememberIdle() {
  controls.classList.remove('pointer-events-none', 'opacity-0');
  clearTimeout(idleTimer);
  idleTimer = setTimeout(() => controls.classList.add('pointer-events-none', 'opacity-0'), 2500);
}

document.body.addEventListener('mousemove', rememberIdle);

const full = document.getElementById('full');

function setFullscreenLabel(on) {
  full.textContent = on ? 'Windowed' : 'Fullscreen';
}

full.addEventListener('click', () => {
  if (window.thePlay) {
    window.thePlay.fullscreen().then(setFullscreenLabel);
  }
});

if (window.thePlay && window.thePlay.onFullscreen) {
  window.thePlay.onFullscreen(setFullscreenLabel);
}

const channel = new BroadcastChannel('theplay-visual');
channel.onmessage = (event) => show(event.data);

const saved = localStorage.getItem('theplay-visual');
if (saved) {
  try {
    show(JSON.parse(saved));
  } catch (error) {
    /* ignore a broken saved picture */
  }
}

if (window.thePlay) {
  window.thePlay.listScreens().then((list) => {
    for (const screen of list) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'rounded-md bg-white px-2.5 py-1.5 text-sm font-medium text-stone-900 hover:bg-stone-200';
      button.textContent = screen.label;
      button.addEventListener('click', () => window.thePlay.sendToScreen(screen.id));
      screens.appendChild(button);
    }
  });
}
