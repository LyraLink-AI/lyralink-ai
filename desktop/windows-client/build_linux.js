const packager = require('electron-packager');

async function run() {
  try {
    const appPaths = await packager({
      dir: '.',
      name: 'Lyralink',
      platform: 'linux',
      arch: 'x64',
      out: 'dist',
      overwrite: true,
      asar: true,
      icon: 'assets/app.png',
      extraResource: ['local'],
      ignore: ['^/web($|/)', '^/php($|/)'],
    });

    console.log('Built:', appPaths.join(', '));
  } catch (err) {
    const msg = err && err.stack ? err.stack : String(err);
    console.error(msg);
    process.exit(1);
  }
}

run();
