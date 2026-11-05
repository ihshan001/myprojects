
//
//  Service.swift
//  ClinicQueue
//
//  Created by Keshana Liyanaarachchi on 2026-03-05.
//

import SwiftUI

enum ServiceDestination {
    case consultation
    case laboratory
    case imaging
    case pharmacy
    case appointment
}

struct Service: Identifiable {
    let id = UUID()
    let icon: String
    let title: String
    let subtitle: String
    let background: Color
    let destination: ServiceDestination
}
