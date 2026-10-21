"use client";

import { Button, Empty } from "@/components/ui";

export default function ErrorPage({ reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <div className="mx-auto w-full max-w-xl px-4 py-16">
      <Empty title="Something went wrong" action={<Button onClick={reset}>Try again</Button>}>
        An unexpected error occurred. Your data is safe; try again, and if it keeps happening come back in a few minutes.
      </Empty>
    </div>
  );
}
