//
//  ClinicQueueApp.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-02-07.
//

import SwiftUI

@main
struct ClinicQueueApp: App {
    @StateObject private var sessionManager = SessionManager()
    @StateObject private var sessionManagerV2 = SessionManagerV2()
    var body: some Scene {
        WindowGroup {
            ContentView().environmentObject(sessionManager)
                .environmentObject(sessionManagerV2)
        }
    }
}
