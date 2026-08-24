import { resolve, parse } from "path";
import fs from "fs";

export default function atriaPhp(opts = {}) {
  const root = process.cwd();
  const {
    input = {},
    base = "/build/",
    outDir = "public/build",
    port = 5173,
  } = opts;

  const hotFile = resolve(root, outDir, "hot");

  function normalizeInput(entries) {
    if (Array.isArray(entries)) {
      const map = {};
      for (const entry of entries) {
        map[parse(entry).name] = resolve(root, entry);
      }
      return map;
    }
    return entries;
  }

  return {
    name: "atria-vite-plugin",

    config() {
      return {
        base,
        publicDir: false,
        build: {
          outDir,
          manifest: true,
          rollupOptions: {
            input: normalizeInput(input),
          },
        },
        server: {
          host: '0.0.0.0',
          port,
          strictPort: true,
          cors: true,
          origin: "http://localhost:" + port,
        },
        hmr: {
            host: 'localhost',
            protocol: 'ws',
            port: 5173,
        },
      };
    },

    configureServer(server) {
      fs.mkdirSync(resolve(root, outDir), { recursive: true });

      const urls = server.resolvedUrls;
      const devUrl = (urls?.local?.[0] ?? `http://localhost:${port}`).replace(
        /\/$/,
        "",
      );
      fs.writeFileSync(hotFile, devUrl);

      function cleanup() {
        if (fs.existsSync(hotFile)) fs.unlinkSync(hotFile);
      }

      process.on("exit", cleanup);
      process.on("SIGINT", () => {
        cleanup();
        process.exit();
      });
      process.on("SIGTERM", () => {
        cleanup();
        process.exit();
      });
    },
  };
}
