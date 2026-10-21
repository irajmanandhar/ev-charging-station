"use client";

import { useEffect } from "react";
import { Button, Empty } from "@/components/ui";

export default function ErrorPage({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  // Production builds hide the message on screen; keep it in the console so it can be diagnosed.
  useEffect(() => {
    console.error(error);
  }, [error]);

  return (
    <div className="mx-auto w-full max-w-xl px-4 py-16">
      <Empty title="Something went wrong" action={<Button onClick={reset}>Try again</Button>}>
        An unexpected error occurred. Your data is safe; try again, and if it keeps happening come back in a few minutes.
        {error.digest && <span className="mt-2 block text-xs text-muted">Error reference: {error.digest}</span>}
      </Empty>
    </div>
  );
}
