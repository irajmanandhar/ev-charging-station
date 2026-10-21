"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useToast } from "@/components/toast";
import { PageHeader } from "@/components/ui";
import { StationForm } from "../station-form";

export default function NewStationPage() {
  const router = useRouter();
  const toast = useToast();
  return (
    <div className="mx-auto max-w-3xl">
      <Link href="/operator/stations" className="text-sm font-medium text-ink-2 hover:text-ink">
        ← Stations
      </Link>
      <div className="mt-3">
        <PageHeader title="Add a station" subtitle="Next you'll add its chargers." />
      </div>
      <StationForm
        onSaved={(s) => {
          toast("Station created. Now add its chargers.");
          router.push(`/operator/stations/${s.id}`);
        }}
      />
    </div>
  );
}
