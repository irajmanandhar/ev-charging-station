"use client";

import { usePathname, useRouter } from "next/navigation";
import { Suspense, useEffect } from "react";
import { useAuth } from "@/lib/auth";
import type { Role } from "@/lib/types";
import { Empty, Spinner, ButtonLink } from "./ui";

function Loading() {
  return (
    <div className="flex flex-1 items-center justify-center gap-3 py-24 text-ink-2">
      <Spinner /> Loading…
    </div>
  );
}

/** Client-side gate. The API enforces the same rules; this just keeps the UI tidy. */
export function RequireAuth(props: { roles?: Role[]; children: React.ReactNode }) {
  return (
    <Suspense fallback={<Loading />}>
      <Gate {...props} />
    </Suspense>
  );
}

function Gate({ roles, children }: { roles?: Role[]; children: React.ReactNode }) {
  const { user, loading } = useAuth();
  const router = useRouter();
  const pathname = usePathname();

  useEffect(() => {
    if (!loading && !user) router.replace(`/login?next=${encodeURIComponent(pathname)}`);
  }, [loading, user, router, pathname]);

  if (loading || !user) return <Loading />;

  if (roles && !roles.includes(user.role)) {
    return (
      <div className="mx-auto w-full max-w-xl px-4 py-16">
        <Empty title="You don't have access to this page" action={<ButtonLink href="/">Go to the map</ButtonLink>}>
          This area is for {roles.join(" and ")} accounts.
        </Empty>
      </div>
    );
  }

  return <>{children}</>;
}
