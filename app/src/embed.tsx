import React from "react";
import { createRoot } from "react-dom/client";
import Navigator, { type NavigatorProps } from "./components/Navigator";

declare global {
  interface Window {
    VisibilityNavigator?: {
      mount: (el: HTMLElement, props: NavigatorProps) => { unmount: () => void };
    };
  }
}

window.VisibilityNavigator = {
  mount(el, props) {
    const root = createRoot(el);
    root.render(<Navigator {...props} />);
    return { unmount: () => root.unmount() };
  },
};
