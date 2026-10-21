import { Suspense } from "react";
import { Skeleton } from "@/components/ui";
import { StationView } from "./station-view";

export default function StationPage({ params }: PageProps<"/stations/[id]">) {
  return (
    <Suspense
      fallback={
        <div className="mx-auto w-full max-w-6xl space-y-4 px-4 py-8 sm:px-6">
          <Skeleton className="h-10 w-2/3" />
          <Skeleton className="h-64" />
        </div>
      }
    >
      <StationView params={params} />
    </Suspense>
  );
}
