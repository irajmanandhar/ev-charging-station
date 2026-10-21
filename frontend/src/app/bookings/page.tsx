import type { Metadata } from "next";
import { RequireAuth } from "@/components/require-auth";
import { MyBookings } from "./my-bookings";

export const metadata: Metadata = { title: "My bookings" };

export default function BookingsPage() {
  return (
    <RequireAuth>
      <MyBookings />
    </RequireAuth>
  );
}
