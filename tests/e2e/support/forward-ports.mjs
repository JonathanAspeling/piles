// Runs inside the browser container. The app and Reverb are baked to be reached
// on localhost:8000 / :8080, which inside the container is the container
// itself — so forward those ports to the host, where compose publishes them.
import net from 'node:net';

for (const port of [8000, 8080]) {
    net.createServer((client) => {
        const upstream = net.connect(port, 'host.docker.internal');
        client.pipe(upstream).pipe(client);
        client.on('error', () => upstream.destroy());
        upstream.on('error', () => client.destroy());
    }).listen(port, '127.0.0.1');
}
