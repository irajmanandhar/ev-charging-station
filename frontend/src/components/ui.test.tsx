import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { OtpInput } from "./otp-input";
import { Alert, BookingStatusBadge, Button, Field, Input, StatusBadge } from "./ui";

describe("Button", () => {
  it("is disabled and does not fire while loading", async () => {
    const onClick = vi.fn();
    render(
      <Button loading onClick={onClick}>
        Save
      </Button>,
    );

    const button = screen.getByRole("button", { name: /save/i });
    expect(button).toBeDisabled();
    await userEvent.click(button);
    expect(onClick).not.toHaveBeenCalled();
  });
});

describe("Field", () => {
  it("labels the input and announces its error", () => {
    render(
      <Field label="Email" error="Already taken">
        <Input type="email" />
      </Field>,
    );

    expect(screen.getByLabelText("Email")).toBeInTheDocument();
    expect(screen.getByRole("alert")).toHaveTextContent("Already taken");
  });
});

describe("status badges", () => {
  it("never relies on colour alone: every state has a text label", () => {
    render(
      <>
        <StatusBadge status="available" />
        <StatusBadge status="offline" />
        <BookingStatusBadge status="no_show" />
      </>,
    );

    expect(screen.getByText("Available")).toBeInTheDocument();
    expect(screen.getByText("Offline")).toBeInTheDocument();
    expect(screen.getByText("Missed")).toBeInTheDocument();
  });

  it("uses role=alert for errors and role=status for info", () => {
    render(
      <>
        <Alert>Broken</Alert>
        <Alert tone="info">FYI</Alert>
      </>,
    );
    expect(screen.getByRole("alert")).toHaveTextContent("Broken");
    expect(screen.getByRole("status")).toHaveTextContent("FYI");
  });
});

describe("OtpInput", () => {
  it("keeps only six digits", async () => {
    const onChange = vi.fn();
    render(<OtpInput value="" onChange={onChange} />);

    await userEvent.type(screen.getByLabelText(/verification code/i), "12a3-45678");

    expect(onChange).toHaveBeenLastCalledWith("8");
    const calls = onChange.mock.calls.map((c) => c[0]);
    expect(calls.every((v: string) => /^\d{0,6}$/.test(v))).toBe(true);
  });
});
