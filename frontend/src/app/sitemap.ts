import type { MetadataRoute } from "next";

const SITE_URL = (process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000").replace(/\/$/, "");

// Station pages are discovered by crawling the map; only the stable entry points are listed here.
export default function sitemap(): MetadataRoute.Sitemap {
  return ["", "/login", "/register"].map((path) => ({ url: `${SITE_URL}${path}` }));
}
