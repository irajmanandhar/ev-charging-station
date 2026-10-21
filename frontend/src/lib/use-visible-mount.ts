"use client";

import { useLayoutEffect, useState } from "react";

/**
 * True only while this component's route is actually shown.
 *
 * With `cacheComponents`, Next keeps recently visited routes alive but hidden, running their effect
 * cleanups on hide and the effects again on show. React-Leaflet destroys its map in that cleanup and
 * never rebuilds it, so a returning route would add layers to a dead map and crash
 * ("Cannot read properties of undefined (reading 'appendChild')"). Rendering the map behind this flag
 * unmounts it on hide and mounts a fresh one on show.
 */
export function useVisibleMount(): boolean {
  const [visible, setVisible] = useState(false);
  useLayoutEffect(() => {
    // Toggled by the route becoming visible or hidden, not derived from render.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setVisible(true);
    return () => setVisible(false);
  }, []);
  return visible;
}
