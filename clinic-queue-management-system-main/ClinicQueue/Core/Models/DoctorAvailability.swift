//
//  DoctorAvailability.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-09.
//

import SwiftUI

struct DoctorAvailability: Identifiable {
    let id = UUID()
    let weekday: String
    let timeRange: String
    let icon: Image?
    let queueLabel: String
    let date: Date
}
