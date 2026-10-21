import type { Metadata } from "next";
import { RequireAuth } from "@/components/require-auth";
import { SubNav } from "@/components/sub-nav";

export const metadata: Metadata = { title: "Admin" };

export default function AdminLayout({ children }: LayoutProps<"/admin">) {
  return (
    <RequireAuth roles={["admin"]}>
      <SubNav
        items={[
          { href: "/admin", label: "Overview", exact: true },
          { href: "/admin/users", label: "Users" },
          { href: "/admin/stations", label: "Stations" },
        ]}
      />
      <div className="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6">{children}</div>
    </RequireAuth>
  );
}
