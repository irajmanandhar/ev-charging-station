import type { Metadata } from "next";
import { RequireAuth } from "@/components/require-auth";
import { SubNav } from "@/components/sub-nav";

export const metadata: Metadata = { title: "Operator", robots: { index: false } };

export default function OperatorLayout({ children }: LayoutProps<"/operator">) {
  return (
    <RequireAuth roles={["operator", "admin"]}>
      <SubNav
        items={[
          { href: "/operator", label: "Overview", exact: true },
          { href: "/operator/stations", label: "Stations" },
        ]}
      />
      <div className="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6">{children}</div>
    </RequireAuth>
  );
}
