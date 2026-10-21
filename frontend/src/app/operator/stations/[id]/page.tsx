import { Suspense } from "react";
import { Skeleton } from "@/components/ui";
import { ManageStation } from "./manage-station";

export default function ManageStationPage({ params }: PageProps<"/operator/stations/[id]">) {
  return (
    <Suspense fallback={<Skeleton className="h-96" />}>
      <ManageStation params={params} />
    </Suspense>
  );
}
