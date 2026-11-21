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
  document.body.classList.remove('idle');
  clearTimeout(idleTimer);
  idleTimer = setTimeout(() => document.body.classList.add('idle'), 2500);
}

document.body.addEventListener('mousemove', rememberIdle);

document.getElementById('full').addEventListener('click', () => {
  if (window.thePlay) {
    window.thePlay.fullscreen();
  }
});

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
      button.textContent = screen.label;
      button.addEventListener('click', () => window.thePlay.sendToScreen(screen.id));
      screens.appendChild(button);
    }
  });
}
