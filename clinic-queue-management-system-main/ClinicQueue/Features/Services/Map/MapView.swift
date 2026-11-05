//
//  MapView.swift
//  ClinicQueue
//
//  Created by Keshana Liyanaarachchi on 2026-03-13.
//



import SwiftUI

// MARK: - Models

enum ClinicFloor: Int, CaseIterable {
    case ground = 1, upper = 2
    var label: String { self == .ground ? "G" : "2" }
    var title: String { self == .ground ? "Ground Floor" : "2nd Floor" }
}

struct ClinicRoom: Identifiable, Equatable {
    let id = UUID()
    let name: String
    let number: String
    let icon: String
    let accent: Color
    let rect: CGRect
    let walkMins: Int
    var isEntrance: Bool = false

    static func == (l: ClinicRoom, r: ClinicRoom) -> Bool { l.id == r.id }
}



extension ClinicRoom {

    static let ground: [ClinicRoom] = [
        ClinicRoom(name: "Entrance",      number: "G00", icon: "door.left.hand.open",
                   accent: Color(hex: "0DC8A4"),
                   rect: CGRect(x: 0.30, y: 0.03, width: 0.40, height: 0.10),
                   walkMins: 0, isEntrance: true),
        ClinicRoom(name: "Registration",  number: "G01", icon: "clipboard.fill",
                   accent: Color(hex: "4A90E2"),
                   rect: CGRect(x: 0.03, y: 0.17, width: 0.43, height: 0.16),
                   walkMins: 1),
        ClinicRoom(name: "Waiting",       number: "G02", icon: "person.3.fill",
                   accent: Color(hex: "27AE60"),
                   rect: CGRect(x: 0.54, y: 0.17, width: 0.43, height: 0.16),
                   walkMins: 1),
        ClinicRoom(name: "Consultation",  number: "G03", icon: "stethoscope",
                   accent: Color(hex: "9B59B6"),
                   rect: CGRect(x: 0.03, y: 0.38, width: 0.43, height: 0.16),
                   walkMins: 2),
        ClinicRoom(name: "Pharmacy",      number: "G04", icon: "pills.fill",
                   accent: Color(hex: "E67E22"),
                   rect: CGRect(x: 0.54, y: 0.38, width: 0.43, height: 0.16),
                   walkMins: 2),
        ClinicRoom(name: "Payment",       number: "G05", icon: "creditcard.fill",
                   accent: Color(hex: "F1C40F"),
                   rect: CGRect(x: 0.03, y: 0.59, width: 0.28, height: 0.14),
                   walkMins: 3),
        ClinicRoom(name: "Restrooms",     number: "G06", icon: "figure.stand",
                   accent: Color(hex: "95A5A6"),
                   rect: CGRect(x: 0.36, y: 0.59, width: 0.28, height: 0.14),
                   walkMins: 1),
        ClinicRoom(name: "Elevator",      number: "G07", icon: "arrow.up.arrow.down.circle.fill",
                   accent: Color(hex: "1A2E44"),
                   rect: CGRect(x: 0.69, y: 0.59, width: 0.28, height: 0.14),
                   walkMins: 2),
      
    ]

    static let upper: [ClinicRoom] = [
        ClinicRoom(name: "Elevator",      number: "2-LFT", icon: "arrow.up.arrow.down.circle.fill",
                   accent: Color(hex: "1A2E44"),
                   rect: CGRect(x: 0.36, y: 0.03, width: 0.28, height: 0.10),
                   walkMins: 0, isEntrance: true),
        ClinicRoom(name: "Specialist",    number: "201", icon: "cross.case.fill",
                   accent: Color(hex: "E74C3C"),
                   rect: CGRect(x: 0.03, y: 0.17, width: 0.43, height: 0.16),
                   walkMins: 1),
        ClinicRoom(name: "Cardiology",    number: "202", icon: "heart.fill",
                   accent: Color(hex: "C0392B"),
                   rect: CGRect(x: 0.54, y: 0.17, width: 0.43, height: 0.16),
                   walkMins: 2),
        ClinicRoom(name: "Laboratory",    number: "203", icon: "testtube.2",
                   accent: Color(hex: "8E44AD"),
                   rect: CGRect(x: 0.03, y: 0.38, width: 0.43, height: 0.16),
                   walkMins: 3),
        ClinicRoom(name: "Radiology",     number: "204", icon: "xmark.shield.fill",
                   accent: Color(hex: "2980B9"),
                   rect: CGRect(x: 0.54, y: 0.38, width: 0.43, height: 0.16),
                   walkMins: 3),
        ClinicRoom(name: "Blood Test",    number: "205", icon: "drop.fill",
                   accent: Color(hex: "E74C3C"),
                   rect: CGRect(x: 0.03, y: 0.59, width: 0.28, height: 0.14),
                   walkMins: 4),
        ClinicRoom(name: "Pathology",     number: "206", icon: "cross.vial.fill",
                   accent: Color(hex: "6C3483"),
                   rect: CGRect(x: 0.36, y: 0.59, width: 0.28, height: 0.14),
                   walkMins: 4),
        ClinicRoom(name: "Restrooms",     number: "207", icon: "figure.stand",
                   accent: Color(hex: "95A5A6"),
                   rect: CGRect(x: 0.69, y: 0.59, width: 0.28, height: 0.14),
                   walkMins: 2),
    ]
}



struct BlueprintCanvas: View {
    let rooms: [ClinicRoom]
    let selected: ClinicRoom?
    let canvasSize: CGSize
    let onTap: (ClinicRoom) -> Void

    @State private var pathProgress: CGFloat = 0
    @State private var glowPhase: CGFloat = 0

    var entrance: ClinicRoom? { rooms.first(where: { $0.isEntrance }) }


    func scaled(_ r: CGRect) -> CGRect {
        CGRect(
            x: r.minX * canvasSize.width,
            y: r.minY * canvasSize.height,
            width: r.width * canvasSize.width,
            height: r.height * canvasSize.height
        )
    }

    func center(_ r: ClinicRoom) -> CGPoint {
        let sr = scaled(r.rect)
        return CGPoint(x: sr.midX, y: sr.midY)
    }

    
    func navPath(from: ClinicRoom, to: ClinicRoom) -> Path {
        let s = center(from)
        let e = center(to)
        let corridorY = canvasSize.height * 0.135

        var p = Path()
        p.move(to: s)
        p.addLine(to: CGPoint(x: s.x, y: corridorY))
        p.addLine(to: CGPoint(x: e.x, y: corridorY))
        p.addLine(to: e)
        return p
    }

    var body: some View {
        ZStack {
          
            RoundedRectangle(cornerRadius: 20)
                .fill(Color(hex: "0A1628"))

            Canvas { ctx, size in
                let step: CGFloat = 18
                var x: CGFloat = 0
                while x < size.width {
                    var p = Path(); p.move(to: CGPoint(x: x, y: 0)); p.addLine(to: CGPoint(x: x, y: size.height))
                    ctx.stroke(p, with: .color(Color.white.opacity(0.035)), lineWidth: 0.5)
                    x += step
                }
                var y: CGFloat = 0
                while y < size.height {
                    var p = Path(); p.move(to: CGPoint(x: 0, y: y)); p.addLine(to: CGPoint(x: size.width, y: y))
                    ctx.stroke(p, with: .color(Color.white.opacity(0.035)), lineWidth: 0.5)
                    y += step
                }
            }
            .frame(width: canvasSize.width, height: canvasSize.height)
            .clipShape(RoundedRectangle(cornerRadius: 20))


            ForEach(rooms) { room in
                let sr = scaled(room.rect)
                let isSelected = selected?.id == room.id
                let isEntrance = room.isEntrance

                ZStack {
              
                    RoundedRectangle(cornerRadius: 8)
                        .fill(
                            isSelected
                                ? room.accent.opacity(0.22)
                                : isEntrance
                                    ? Color(hex: "0DC8A4").opacity(0.10)
                                    : Color.white.opacity(0.04)
                        )
                        .frame(width: sr.width, height: sr.height)

                    
                    RoundedRectangle(cornerRadius: 8)
                        .stroke(
                            isSelected ? room.accent :
                            isEntrance ? Color(hex: "0DC8A4").opacity(0.7) :
                            Color.white.opacity(0.18),
                            lineWidth: isSelected ? 2 : 1
                        )
                        .frame(width: sr.width, height: sr.height)

                   
                    if isSelected {
                        RoundedRectangle(cornerRadius: 8)
                            .stroke(room.accent.opacity(0.3), lineWidth: 6)
                            .blur(radius: 4)
                            .frame(width: sr.width, height: sr.height)
                    }

       
                    VStack(spacing: 3) {
                        Image(systemName: room.icon)
                            .font(.system(size: sr.height < 50 ? 11 : 14, weight: .semibold))
                            .foregroundColor(
                                isSelected ? room.accent :
                                isEntrance ? Color(hex: "0DC8A4") :
                                Color.white.opacity(0.7)
                            )
                        Text(room.name)
                            .font(.system(size: sr.height < 50 ? 8 : 9.5, weight: .bold))
                            .foregroundColor(
                                isSelected ? Color.white :
                                Color.white.opacity(0.65)
                            )
                            .multilineTextAlignment(.center)
                            .lineLimit(2)
                        Text(room.number)
                            .font(.system(size: 7, weight: .medium).monospacedDigit())
                            .foregroundColor(Color.white.opacity(0.35))
                    }
                    .frame(width: sr.width - 8, height: sr.height - 6)
                }
                .position(x: sr.midX, y: sr.midY)
                .onTapGesture {
                    onTap(room)
                }
                .animation(.spring(response: 0.3, dampingFraction: 0.6), value: isSelected)
            }


            Text("— MAIN CORRIDOR —")
                .font(.system(size: 7.5, weight: .bold))
                .tracking(2)
                .foregroundColor(Color.white.opacity(0.2))
                .position(x: canvasSize.width / 2, y: canvasSize.height * 0.135)

            if let dest = selected, !dest.isEntrance, let from = entrance {
                let path = navPath(from: from, to: dest)


                path
                    .stroke(dest.accent.opacity(0.25), lineWidth: 8)
                    .blur(radius: 4)

                AnimatedDashPath(path: path, color: Color(hex: "0DC8A4"), progress: pathProgress)

                Circle()
                    .fill(dest.accent)
                    .frame(width: 12, height: 12)
                    .overlay(Circle().stroke(Color.white, lineWidth: 2))
                    .shadow(color: dest.accent, radius: 6)
                    .position(center(dest))
                    .scaleEffect(1 + 0.15 * sin(glowPhase))
            }

     
            if let from = entrance {
                ZStack {
                    Circle()
                        .fill(Color(hex: "0DC8A4").opacity(0.2))
                        .frame(width: 24, height: 24)
                        .scaleEffect(1 + 0.4 * sin(glowPhase))
                    Circle()
                        .fill(Color(hex: "0DC8A4"))
                        .frame(width: 10, height: 10)
                        .overlay(Circle().stroke(Color.white, lineWidth: 1.5))
                }
                .position(center(from))
            }
        }
        .frame(width: canvasSize.width, height: canvasSize.height)
        .clipShape(RoundedRectangle(cornerRadius: 20))
        .shadow(color: Color(hex: "0DC8A4").opacity(0.12), radius: 20, x: 0, y: 8)
        .onChange(of: selected) { _ in
            pathProgress = 0
            withAnimation(.easeInOut(duration: 0.9)) {
                pathProgress = 1
            }
        }
        .onAppear {
            withAnimation(.linear(duration: 2).repeatForever(autoreverses: false)) {
                glowPhase = .pi * 2
            }
            withAnimation(.easeInOut(duration: 0.9)) {
                pathProgress = 1
            }
        }
    }
}



struct AnimatedDashPath: View {
    let path: Path
    let color: Color
    let progress: CGFloat

    var body: some View {
        path
            .trim(from: 0, to: progress)
            .stroke(
                color,
                style: StrokeStyle(lineWidth: 2.5, lineCap: .round, dash: [7, 5])
            )
            .animation(.easeInOut(duration: 0.9), value: progress)
    }
}



struct DirectionCard: View {
    let room: ClinicRoom
    let floor: ClinicFloor

    var steps: [String] {
        floor == .ground
        ? ["Start at Main Entrance",
           "Walk along the main corridor",
           "Follow the \(room.accent == Color(hex: "E67E22") ? "orange" : "blue") signs",
           "Room \(room.number) — \(room.name)"]
        : ["Take Elevator to Floor 2",
           "Exit and walk along corridor",
           "Room \(room.number) is on your \(room.rect.minX < 0.5 ? "left" : "right")",
           "Arrive at \(room.name)"]
    }

    var body: some View {
        VStack(spacing: 0) {

  
            HStack(spacing: 0) {
                // Accent strip
                RoundedRectangle(cornerRadius: 0)
                    .fill(room.accent)
                    .frame(width: 4)

                HStack(spacing: 12) {
                    ZStack {
                        RoundedRectangle(cornerRadius: 10)
                            .fill(room.accent.opacity(0.15))
                            .frame(width: 44, height: 44)
                        Image(systemName: room.icon)
                            .font(.system(size: 18, weight: .semibold))
                            .foregroundColor(room.accent)
                    }

                    VStack(alignment: .leading, spacing: 3) {
                        Text(room.name)
                            .font(.system(size: 16, weight: .bold))
                            .foregroundColor(Color(hex: "1A2E44"))
                        Text("Room \(room.number) · \(room.walkMins) min walk")
                            .font(.system(size: 12))
                            .foregroundColor(.gray)
                    }
                    Spacer()

                 
                    VStack(spacing: 2) {
                        Image(systemName: "figure.walk")
                            .font(.system(size: 16))
                        Text("\(room.walkMins)m")
                            .font(.system(size: 11, weight: .bold))
                    }
                    .foregroundColor(Color(hex: "0DC8A4"))
                }
                .padding(.horizontal, 14)
                .padding(.vertical, 14)
            }
            .background(Color.white)

            Divider()


            VStack(alignment: .leading, spacing: 0) {
                ForEach(Array(steps.enumerated()), id: \.offset) { i, step in
                    HStack(alignment: .top, spacing: 12) {
              
                        VStack(spacing: 0) {
                            ZStack {
                                Circle()
                                    .fill(i == steps.count - 1
                                          ? room.accent
                                          : Color(hex: "0DC8A4").opacity(0.15))
                                    .frame(width: 26, height: 26)
                                if i == steps.count - 1 {
                                    Image(systemName: "checkmark")
                                        .font(.system(size: 10, weight: .bold))
                                        .foregroundColor(.white)
                                } else {
                                    Text("\(i + 1)")
                                        .font(.system(size: 11, weight: .bold))
                                        .foregroundColor(Color(hex: "0DC8A4"))
                                }
                            }
                            if i < steps.count - 1 {
                                Rectangle()
                                    .fill(Color(hex: "0DC8A4").opacity(0.2))
                                    .frame(width: 1.5, height: 22)
                            }
                        }

                        Text(step)
                            .font(.system(size: 13))
                            .foregroundColor(
                                i == steps.count - 1
                                    ? Color(hex: "1A2E44")
                                    : Color(hex: "1A2E44").opacity(0.6)
                            )
                            .fontWeight(i == steps.count - 1 ? .semibold : .regular)
                            .padding(.top, 4)
                    }
                    .padding(.horizontal, 14)
                    .padding(.bottom, i < steps.count - 1 ? 0 : 14)
                }
            }
            .padding(.top, 12)
            .background(Color(hex: "F8FAFC"))
        }
        .clipShape(RoundedRectangle(cornerRadius: 18))
        .overlay(
            RoundedRectangle(cornerRadius: 18)
                .stroke(Color(hex: "E8ECF2"), lineWidth: 1)
        )
        .shadow(color: Color.black.opacity(0.06), radius: 12, x: 0, y: 4)
    }
}



struct MapView: View {
    @Environment(\.dismiss) private var dismiss
    @State private var selectedFloor: ClinicFloor = .ground
    @State private var selectedRoom: ClinicRoom? = nil
    @State private var floorAnim = false

    var rooms: [ClinicRoom] {
        selectedFloor == .ground ? ClinicRoom.ground : ClinicRoom.upper
    }

    var body: some View {
        NavigationView {
            ZStack {
                Color(hex: "F0F4F8").ignoresSafeArea()

                VStack(spacing: 0) {

              
                    HStack(spacing: 0) {
                        ForEach(ClinicFloor.allCases, id: \.self) { floor in
                            Button {
                                withAnimation(.spring(response: 0.38, dampingFraction: 0.72)) {
                                    selectedFloor = floor
                                    selectedRoom = nil
                                }
                                UIImpactFeedbackGenerator(style: .medium).impactOccurred()
                            } label: {
                                HStack(spacing: 8) {
                                
                                    ZStack {
                                        Circle()
                                            .fill(selectedFloor == floor
                                                  ? Color(hex: "0DC8A4")
                                                  : Color.white.opacity(0.0))
                                            .frame(width: 28, height: 28)
                                        Text(floor.label)
                                            .font(.system(size: 13, weight: .black))
                                            .foregroundColor(
                                                selectedFloor == floor ? .white : Color(hex: "1A2E44").opacity(0.4)
                                            )
                                    }

                                    VStack(alignment: .leading, spacing: 1) {
                                        Text(floor.title)
                                            .font(.system(size: 14, weight: .bold))
                                            .foregroundColor(
                                                selectedFloor == floor ? Color(hex: "1A2E44") : Color(hex: "1A2E44").opacity(0.4)
                                            )
                                    }
                                }
                                .frame(maxWidth: .infinity)
                                .padding(.vertical, 13)
                                .background(
                                    selectedFloor == floor
                                        ? Color.white
                                        : Color.clear
                                )
                                .cornerRadius(12)
                            }
                            .buttonStyle(PlainButtonStyle())
                        }
                    }
                    .padding(4)
                    .background(
                        RoundedRectangle(cornerRadius: 15)
                            .fill(Color(hex: "E2E8F0"))
                    )
                    .shadow(color: Color.black.opacity(0.06), radius: 8, x: 0, y: 3)
                    .padding(.horizontal, 16)
                    .padding(.top, 12)
                    .padding(.bottom, 14)

                    ScrollView(showsIndicators: false) {
                        VStack(spacing: 16) {

            
                            GeometryReader { geo in
                                let w = geo.size.width
                                let h = w * 1.18
                                BlueprintCanvas(
                                    rooms: rooms,
                                    selected: selectedRoom,
                                    canvasSize: CGSize(width: w, height: h),
                                    onTap: { room in
                                        withAnimation(.spring(response: 0.3, dampingFraction: 0.65)) {
                                            selectedRoom = selectedRoom?.id == room.id ? nil : room
                                        }
                                        UIImpactFeedbackGenerator(style: .medium).impactOccurred()
                                    }
                                )
                                .frame(width: w, height: h)
                            }
                            .aspectRatio(1/1.18, contentMode: .fit)
                            .padding(.horizontal, 16)

                            HStack(spacing: 18) {
                                legendItem(dot: Color(hex: "0DC8A4"), pulse: true, text: "You are here")
                                legendItem(dot: Color(hex: "0DC8A4"), pulse: false, text: "Path")
                                legendItem(dot: .white.opacity(0.3), pulse: false, text: "Tap room")
                                Spacer()
                            }
                            .padding(.horizontal, 20)

                            if let room = selectedRoom, !room.isEntrance {
                                DirectionCard(room: room, floor: selectedFloor)
                                    .padding(.horizontal, 16)
                                    .transition(.asymmetric(
                                        insertion: .move(edge: .bottom).combined(with: .opacity),
                                        removal: .opacity
                                    ))
                            } else if selectedRoom == nil {
                           
                                HStack(spacing: 12) {
                                    Image(systemName: "hand.tap.fill")
                                        .font(.system(size: 20))
                                        .foregroundColor(Color(hex: "0DC8A4"))
                                    VStack(alignment: .leading, spacing: 2) {
                                        Text("Find your destination")
                                            .font(.system(size: 14, weight: .bold))
                                            .foregroundColor(Color(hex: "1A2E44"))
                                        Text("Tap any room on the map to get directions")
                                            .font(.system(size: 12))
                                            .foregroundColor(.gray)
                                    }
                                    Spacer()
                                }
                                .padding(16)
                                .background(Color.white)
                                .cornerRadius(14)
                                .overlay(
                                    RoundedRectangle(cornerRadius: 14)
                                        .stroke(Color(hex: "E8ECF2"), lineWidth: 1)
                                )
                                .padding(.horizontal, 16)
                                .transition(.opacity)
                            }

                            Spacer(minLength: 40)
                        }
                    }
                    .animation(.spring(response: 0.4, dampingFraction: 0.75), value: selectedRoom)
                }
            }
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
//                ToolbarItem(placement: .navigationBarLeading) {
//                    Button { dismiss() } label: {
//                        HStack(spacing: 6) {
//                            Image(systemName: "chevron.left")
//                                .font(.system(size: 15, weight: .semibold))
//                            Text("Back")
//                                .font(.system(size: 15, weight: .medium))
//                        }
//                        .foregroundColor(Color(hex: "1A2E44"))
//                    }
//                }
//                ToolbarItem(placement: .principal) {
//                    VStack(spacing: 0) {
//                        Text("Indoor Map")
//                            .font(.system(size: 17, weight: .black))
//                            .foregroundColor(Color(hex: "1A2E44"))
//                        Text("ClinicQueue Medical Center")
//                            .font(.system(size: 10, weight: .medium))
//                            .foregroundColor(Color(hex: "0DC8A4"))
//                    }
//                }
                ToolbarItem(placement: .navigationBarTrailing) {
             
                    Button {
                        withAnimation { selectedRoom = nil }
                    } label: {
                        Image(systemName: "arrow.counterclockwise")
                            .font(.system(size: 14, weight: .semibold))
                            .foregroundColor(selectedRoom != nil ? Color(hex: "0DC8A4") : .gray.opacity(0.4))
                    }
                    .disabled(selectedRoom == nil)
                }
            }
        }
    }

    func legendItem(dot: Color, pulse: Bool, text: String) -> some View {
        HStack(spacing: 5) {
            ZStack {
                if pulse {
                    Circle().fill(dot.opacity(0.3)).frame(width: 10, height: 10)
                        .scaleEffect(1.4)
                }
                Circle().fill(dot).frame(width: 7, height: 7)
            }
            Text(text)
                .font(.system(size: 11))
                .foregroundColor(.gray)
        }
    }
}



#Preview {
    MapView()
}
