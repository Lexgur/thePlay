const fs = require('fs');
const path = require('path');

exports.default = async function afterPack(context) {
  const name = context.packager.executableName;
  const bin = path.join(context.appOutDir, name);
  const real = path.join(context.appOutDir, name + '.bin');
  fs.renameSync(bin, real);
  fs.writeFileSync(bin, '#!/bin/sh\nexec "$(dirname "$0")/' + name + '.bin" --no-sandbox "$@"\n');
  fs.chmodSync(bin, 0o755);
};
