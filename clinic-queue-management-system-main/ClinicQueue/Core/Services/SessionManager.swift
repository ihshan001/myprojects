//
//  SessionManager.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-09.
//

import SwiftUI
import Combine


class SessionManager: ObservableObject {
    @Published var isLoggedIn: Bool = false
    @Published var userId: UUID = UUID()
    @Published var name: String = ""
    @Published var age: Int? = nil
    @Published var gender: String = ""
    @Published var phoneNumber: String = ""
    @Published var loginMethod: LoginMethod = .guest
    
    
    @Published var currentClinicVisit: ClinicVisit? = nil
    @Published var clinicSession: PatientSession? = nil
    @Published var labSessions: [PatientSession] = []
    @Published var imagingSessions: [PatientSession] = []
    
    func startGuestSession() {
        self.userId = UUID()
        self.isLoggedIn = true
        self.loginMethod = .guest
    }
    
    func startPhoneSession(phoneNumber: String) {
        self.userId = UUID()
        self.phoneNumber = phoneNumber
        self.isLoggedIn = true
        self.loginMethod = .phone
    }
    
    func startSSOSession(provider: LoginMethod) {
        self.userId = UUID()
        self.isLoggedIn = true
        self.loginMethod = provider
    }
}

enum LoginMethod {
    case guest
    case phone
    case apple
    case google
}


//var activeClinicVisit: ClinicVisit? {
//    if let visit = currentClinicVisit, !visit.steps.allSatisfy({ $0.isFinished }) {
//        return visit
//    }
//    return nil
//}
