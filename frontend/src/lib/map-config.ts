/**
 * Map tiles. The public OpenStreetMap server is for light use only (see its tile usage policy),
 * so production should point NEXT_PUBLIC_TILE_URL at a provider you have an agreement with
 * (MapTiler, Stadia, a self-hosted tile server, ...).
 */
export const TILE_URL = process.env.NEXT_PUBLIC_TILE_URL || "https://tile.openstreetmap.org/{z}/{x}/{y}.png";

export const TILE_ATTRIBUTION =
  process.env.NEXT_PUBLIC_TILE_ATTRIBUTION || '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
