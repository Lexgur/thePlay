# thePlay

Desktop app for running a play from a script. Open a `.docx` or `.pdf`, attach YouTube sounds to selected text, and bind each group to a key. A second window shows that group's image or video.

Space fades all sounds out over 2 seconds. The picture stays up.

Needs Node, PHP, ffmpeg, and yt-dlp.

## Run

```bash
export PATH="$HOME/.local/node/bin:$HOME/.local/bin:$PATH"
npm start
```

Or `bin/theplay`, which sets that `PATH` and starts the app.

## Build

```bash
export PATH="$HOME/.local/node/bin:$HOME/.local/bin:$PATH"
npm run pack
```

Open `dist/thePlay-0.1.0.AppImage`.
